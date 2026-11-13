<?php
/* Loaded by run_tests.php: real HTTP flows and deterministic database races. */

function financial_fixture(int $count = 1): array
{
    global $demo, $BASE;
    clear_shortlist($demo);
    $st = screening(2, 10);
    $seats = array_map(fn($r) => (int) $r['seat_id'], all(
        "SELECT s.seat_id FROM seats s WHERE s.hall_id = ? AND s.surcharge = 0
           AND NOT EXISTS (SELECT 1 FROM booking_seats bs
                            WHERE bs.showtime_id = ? AND bs.seat_id = s.seat_id)
          ORDER BY s.seat_id LIMIT $count", [$st['hall_id'], $st['showtime_id']]));
    if (count($seats) !== $count) { throw new RuntimeException('Fixture needs free standard seats'); }
    $b = new Browser($BASE); $b->login('demo@cinesphere.sg', 'Demo@123');
    $a = new Browser($BASE); $a->login('admin@cinesphere.sg', 'Admin@123');
    return [$b, $a, $st, $seats];
}

tc('TC43', 'F6, F10', 'Changed checkout price needs explicit reconfirmation',
   'Customer opens checkout; staff raises base price by S$5; customer submits old page, then reviews and confirms',
   'Old quote creates no booking; refreshed checkout books the new price', HTTPDB,
   function () use ($demo) {
       [$b, $a, $st, $seats] = financial_fixture();
       $b->post('process_preference.php', ['showtime_id' => $st['showtime_id'], 'seat_ids' => (string)$seats[0]]);
       $p = one('SELECT * FROM preferences WHERE user_id=?', [$demo]);
       $b->get('preferences.php'); $quote = $b->checkoutQuote ?? '';
       $before = (int)one('SELECT COUNT(*) n FROM bookings')['n'];
       $a->post('admin_action.php', ['action'=>'set_price', 'showtime_id'=>$st['showtime_id'], 'base_price'=>(float)$st['base_price']+5]);
       $h = $b->post('process_booking.php', ['confirm'=>[$p['pref_id']], 'gateway'=>'success', 'agree'=>1, 'quote'=>$quote]);
       $after = (int)one('SELECT COUNT(*) n FROM bookings')['n'];
       $preserved = one('SELECT est_total FROM preferences WHERE pref_id=?', [$p['pref_id']]);
       $b->post('process_booking.php', ['confirm'=>[$p['pref_id']], 'gateway'=>'success', 'agree'=>1]);
       $bk = one("SELECT total_amount FROM bookings WHERE user_id=? AND showtime_id=? AND booking_status='confirmed' ORDER BY booking_id DESC LIMIT 1", [$demo,$st['showtime_id']]);
       $expected = (float)$p['est_total']+5;
       return [$before===$after && $preserved && has($h,'price') && $bk && abs((float)$bk['total_amount']-$expected)<0.001,
               "Old submission created ".($after-$before)." booking(s); reviewed total ".($bk['total_amount']??'none')."; expected $expected"];
   });

tc('TC44', 'F10', 'Declined or missing upgrade payment cannot change a booking',
   'Move a paid standard seat to premium with no payment approval, then with declined payment',
   'Both refused; original seat, total, history and adjustment log unchanged', HTTPDB,
   function () use ($demo) {
       [$b,$a,$st,$seats]=financial_fixture();
       $bk=book($b,$demo,(int)$st['showtime_id'],$seats);
       $to=one('SELECT seat_id FROM seats WHERE hall_id=? AND surcharge>0 AND seat_id NOT IN (SELECT seat_id FROM booking_seats WHERE showtime_id=?) ORDER BY seat_id LIMIT 1',[$st['hall_id'],$st['showtime_id']]);
       $form=['action'=>'move_seat','booking_id'=>$bk['booking_id'],'from_seat'=>$seats[0],'to_seat'=>$to['seat_id'],'reason'=>'Requested seat upgrade'];
       $a->post('admin_action.php',$form);
       $a->post('admin_action.php',$form+['upgrade_payment'=>'declined']);
       $now=one('SELECT total_amount FROM bookings WHERE booking_id=?',[$bk['booking_id']]);
       $held=one('SELECT seat_id FROM booking_seats WHERE booking_id=?',[$bk['booking_id']]);
       $history=one('SELECT seat_id FROM booking_seat_history WHERE booking_id=?',[$bk['booking_id']]);
       $logs=(int)one('SELECT COUNT(*) n FROM booking_adjustments WHERE booking_id=?',[$bk['booking_id']])['n'];
       return [(int)$held['seat_id']===$seats[0] && (int)$history['seat_id']===$seats[0] && $now['total_amount']===$bk['total_amount'] && $logs===0,
               "Seat {$held['seat_id']}; total {$now['total_amount']}; adjustment rows $logs"];
   });

tc('TC45', 'F10', 'Approved upgrade and cancellation balance exactly',
   'Approve simulated supplementary payment for a premium upgrade, then cancel',
   'Upgrade is recorded as paid; refund equals original payment plus approved supplement; no pay-later notice', HTTPDB,
   function () use ($demo) {
       [$b,$a,$st,$seats]=financial_fixture();
       $bk=book($b,$demo,(int)$st['showtime_id'],$seats);
       $to=one('SELECT seat_id,surcharge FROM seats WHERE hall_id=? AND surcharge>0 AND seat_id NOT IN (SELECT seat_id FROM booking_seats WHERE showtime_id=?) ORDER BY seat_id LIMIT 1',[$st['hall_id'],$st['showtime_id']]);
       $a->post('admin_action.php',['action'=>'move_seat','booking_id'=>$bk['booking_id'],'from_seat'=>$seats[0],'to_seat'=>$to['seat_id'],'reason'=>'Approved premium upgrade','upgrade_payment'=>'success']);
       $move=one("SELECT amount_change,detail FROM booking_adjustments WHERE booking_id=? AND action='seat_change'",[$bk['booking_id']]);
       $a->post('admin_action.php',['action'=>'cancel_booking','booking_id'=>$bk['booking_id'],'reason'=>'Customer no longer attending']);
       $refund=one("SELECT amount_change FROM booking_adjustments WHERE booking_id=? AND action='booking_cancel'",[$bk['booking_id']]);
       $mail=implode('\n',array_filter(explode('=====END=====',mails()),fn($m)=>str_contains($m,$bk['booking_ref'].' updated')));
       $paid=(float)$bk['total_amount']+(float)$to['surcharge'];
       return [$move && has($move['detail'],'payment approved') && abs((float)$refund['amount_change']+$paid)<0.001 && !str_contains($mail,'at the box office before the film') && has($mail,'paid'),
               'Paid '.$paid.'; refunded '.(-(float)($refund['amount_change']??0)).'; settlement log '.($move['detail']??'missing')];
   });

/* The trigger pauses the REAL partial-refund function while it holds its locks.
   A second PHP process then calls the REAL cancellation function. No production
   code is patched or given test hooks; both processes use the disposable DB. */
function concurrent_refund_cancel(array $bk, int $seat, ?array $http = null): array
{
    global $db,$env,$cleanup,$TEST_DB;
    $lock=$TEST_DB.'_refund_gate';
    one('SELECT GET_LOCK(?, 1) ok',[$lock]);
    $db->query("CREATE TRIGGER review_refund_gate BEFORE DELETE ON booking_seats
                FOR EACH ROW SET @review_gate = GET_LOCK('$lock', 15)");
    $processes=[]; $files=[];
    $spawn=function(string $mode) use (&$processes,&$files,$bk,$seat,$env,$http) {
        $file=tempnam(sys_get_temp_dir(),'cs-race-'); $files[]=$file;
        $idFile=$file.'.id'; $files[]=$idFile;
        $null=PHP_OS_FAMILY==='Windows'?'NUL':'/dev/null';
        $p=proc_open([PHP_BINARY,__DIR__.'/financial_worker.php',$mode,(string)$bk['booking_id'],(string)$seat,$idFile],
            [0=>['pipe','r'],1=>['file',$file,'w'],2=>['file',$file.'.err','w']],$pipes,null,$env,['bypass_shell'=>true]);
        fwrite($pipes[0], json_encode($http)); fclose($pipes[0]);
        $files[]=$file.'.err'; $processes[]=$p;
        $until=microtime(true)+8;
        do { clearstatcache(); if(is_file($idFile)) return [$p,$file,(int)file_get_contents($idFile)]; usleep(20000); } while(microtime(true)<$until);
        throw new RuntimeException('Worker did not start');
    };
    $wait=function(int $id, string $needle) use ($TEST_DB) {
        $until=microtime(true)+8;
        do {
            foreach(all('SHOW FULL PROCESSLIST') as $p) {
                if(($id === 0 ? ($p['db']??'')===$TEST_DB : (int)$p['Id']===$id)
                    && stripos((string)$p['Info'].' '.(string)$p['State'],$needle)!==false) return;
            }
            usleep(20000);
        } while(microtime(true)<$until);
        throw new RuntimeException('Worker did not reach expected lock wait: '.$needle);
    };
    try {
        [$p1,$f1,$id1]=$spawn('refund'); $wait($id1,'User lock');
        [$p2,$f2,$id2]=$spawn($http ? 'http' : 'cancel'); $wait($id2,'FROM showtimes WHERE showtime_id');
        one('SELECT RELEASE_LOCK(?) ok',[$lock]);
        $code1=proc_close($p1); $code2=proc_close($p2); $processes=[];
        if($code1!==0 || $code2!==0) throw new RuntimeException('Worker failure: '.file_get_contents($f1.'.err').file_get_contents($f2.'.err'));
        return [json_decode(file_get_contents($f1),true),json_decode(file_get_contents($f2),true)];
    } finally {
        one('SELECT RELEASE_LOCK(?) ok',[$lock]);
        foreach($processes as $p) { if(is_resource($p)) { proc_terminate($p); proc_close($p); } }
        $db->query('DROP TRIGGER IF EXISTS review_refund_gate');
        foreach($files as $file) { if(is_file($file)) unlink($file); }
    }
}

tc('TC46', 'F8, F10', 'Concurrent partial refund and cancellation use the latest total',
   'Pause a real partial refund inside its transaction; start real staff cancellation on another connection; release refund',
   'One partial refund plus one cancellation equals original payment, no seats held', 'Automated: two PHP workers + database lock barrier',
   function () use ($demo) {
       [$b,$a,$st,$seats]=financial_fixture(2); $bk=book($b,$demo,(int)$st['showtime_id'],$seats);
       [$partial,$cancel]=concurrent_refund_cancel($bk,$seats[0]);
       $refunded=-(float)one('SELECT SUM(amount_change) amount FROM booking_adjustments WHERE booking_id=?',[$bk['booking_id']])['amount'];
       $held=(int)one('SELECT COUNT(*) n FROM booking_seats WHERE booking_id=?',[$bk['booking_id']])['n'];
       return [abs($refunded-(float)$bk['total_amount'])<0.001 && $held===0,
               'Original paid '.$bk['total_amount'].'; partial '.$partial['refund'].'; cancellation '.$cancel['refund'].'; total refunded '.$refunded];
   });

tc('TC47', 'F6, R7', 'Missing or forged checkout quote is rejected',
   'Submit a valid preference and CSRF token with an empty quote, then a forged quote',
   'No booking or payment attempt created; preference retained', HTTPDB,
   function () use ($demo) {
       [$b,$a,$st,$seats]=financial_fixture();
       $b->post('process_preference.php',['showtime_id'=>$st['showtime_id'],'seat_ids'=>(string)$seats[0]]);
       $p=one('SELECT pref_id FROM preferences WHERE user_id=?',[$demo]);
       $before=(int)one('SELECT COUNT(*) n FROM bookings')['n'];
       foreach(['','forged-token'] as $quote) $b->post('process_booking.php',['confirm'=>[$p['pref_id']],'gateway'=>'success','agree'=>1,'quote'=>$quote]);
       $after=(int)one('SELECT COUNT(*) n FROM bookings')['n'];
       return [$before===$after && one('SELECT pref_id FROM preferences WHERE pref_id=?',[$p['pref_id']]),'Booking count '.$before.' -> '.$after];
   });

tc('TC48', 'F10', 'Refund-all validates the exact currently held seat set',
   'Submit all held seats plus an unrelated seat ID',
   'Reject stale/extra IDs; do not cancel the whole booking', HTTPDB,
   function () use ($demo) {
       [$b,$a,$st,$seats]=financial_fixture(); $bk=book($b,$demo,(int)$st['showtime_id'],$seats);
       $a->post('admin_action.php',['action'=>'refund_seats','booking_id'=>$bk['booking_id'],'seat_ids'=>[$seats[0],999999],'reason'=>'Stale seat selection']);
       $status=one('SELECT booking_status FROM bookings WHERE booking_id=?',[$bk['booking_id']])['booking_status'];
       return [$status==='confirmed','Booking status: '.$status];
   });

tc('TC49', 'F8, F10', 'Concurrent customer cancellation reports only the remaining refund',
   'Pause staff partial refund; submit customer cancellation over HTTP; release refund',
   'Email and page show the remaining amount and seats, not the original total', 'Automated: PHP worker + concurrent authenticated HTTP + database lock barrier',
   function () use ($demo) {
       [$b,$a,$st,$seats]=financial_fixture(2); $bk=book($b,$demo,(int)$st['showtime_id'],$seats);
       $request=$b->workerRequest('cancel_booking.php',['booking_id'=>$bk['booking_id']]);
       [$partial,$response]=concurrent_refund_cancel($bk,$seats[0],$request);
       $remaining=round((float)$bk['total_amount']-$partial['refund'],2);
       $mail=implode('\n',array_filter(explode('=====END=====',mails()),fn($m)=>str_contains($m,$bk['booking_ref'].' cancelled')));
       $removed=one('SELECT seat_row,seat_number FROM seats WHERE seat_id=?',[$seats[0]]);
       $oldSeat=$removed['seat_row'].$removed['seat_number'];
       $status=one('SELECT booking_status FROM bookings WHERE booking_id=?',[$bk['booking_id']])['booking_status'];
       return [$status==='cancelled' && has($response['html'],'A refund of S$'.number_format($remaining,2))
               && str_contains($mail,number_format($remaining,2)) && !str_contains($mail,$oldSeat),
               'Expected remaining refund '.$remaining.'; cancellation email '.($mail ? 'captured' : 'missing')];
   });

tc('TC50', 'F10', 'Downgrade and refund-all return exactly the amount paid',
   'Approve upgrade; downgrade to original seat without extra payment; refund all remaining seats',
   'Downgrade refunds the surcharge; final cancellation refunds the remainder; net adjustments equal original payment', HTTPDB,
   function () use ($demo) {
       [$b,$a,$st,$seats]=financial_fixture(); $bk=book($b,$demo,(int)$st['showtime_id'],$seats);
       $to=one('SELECT seat_id FROM seats WHERE hall_id=? AND surcharge>0 AND seat_id NOT IN (SELECT seat_id FROM booking_seats WHERE showtime_id=?) ORDER BY seat_id LIMIT 1',[$st['hall_id'],$st['showtime_id']]);
       $form=['action'=>'move_seat','booking_id'=>$bk['booking_id'],'reason'=>'Customer changes seat type'];
       $a->post('admin_action.php',$form+['from_seat'=>$seats[0],'to_seat'=>$to['seat_id'],'upgrade_payment'=>'success']);
       $a->post('admin_action.php',$form+['from_seat'=>$to['seat_id'],'to_seat'=>$seats[0]]);
       $a->post('admin_action.php',['action'=>'refund_seats','booking_id'=>$bk['booking_id'],'seat_ids'=>$seats,'reason'=>'Refund remaining tickets']);
       $status=one('SELECT booking_status FROM bookings WHERE booking_id=?',[$bk['booking_id']])['booking_status'];
       $net=(float)one('SELECT SUM(amount_change) amount FROM booking_adjustments WHERE booking_id=?',[$bk['booking_id']])['amount'];
       return [$status==='cancelled' && abs($net+(float)$bk['total_amount'])<0.001,'Final status '.$status.'; net adjustments '.$net];
   });

tc('TC51', 'F6, F10', 'Price change while checkout waits is detected after locking',
   'Hold screening lock; start checkout with a valid quote; update price and shortlist in the holding transaction; release lock',
   'Checkout reloads the locked prices and refuses the stale quote without booking', 'Automated: authenticated HTTP worker + controlled price transaction',
   function () use ($demo) {
       global $db,$env,$TEST_DB;
       [$b,$a,$st,$seats]=financial_fixture();
       $b->post('process_preference.php',['showtime_id'=>$st['showtime_id'],'seat_ids'=>(string)$seats[0]]);
       $p=one('SELECT * FROM preferences WHERE user_id=?',[$demo]);
       $request=$b->workerRequest('process_booking.php',['confirm'=>[$p['pref_id']],'gateway'=>'success','agree'=>1,'quote'=>$b->checkoutQuote]);
       $before=(int)one('SELECT COUNT(*) n FROM bookings')['n'];
       $file=tempnam(sys_get_temp_dir(),'cs-price-'); $proc=null;
       $db->begin_transaction();
       try {
           one('SELECT showtime_id FROM showtimes WHERE showtime_id=? FOR UPDATE',[$st['showtime_id']]);
           $proc=proc_open([PHP_BINARY,__DIR__.'/financial_worker.php','http','0','0',$file.'.id'],
               [0=>['pipe','r'],1=>['file',$file,'w'],2=>['file',$file.'.err','w']],$pipes,null,$env,['bypass_shell'=>true]);
           fwrite($pipes[0],json_encode($request)); fclose($pipes[0]);
           $waiting=false; $until=microtime(true)+8;
           do {
               foreach(all('SHOW FULL PROCESSLIST') as $r) {
                   if((int)$r['Id']!==$db->thread_id && ($r['db']??'')===$TEST_DB
                      && stripos((string)$r['Info'],'FROM showtimes WHERE showtime_id')!==false) { $waiting=true; break; }
               }
               if(!$waiting) usleep(20000);
           } while(!$waiting && microtime(true)<$until);
           if(!$waiting) throw new RuntimeException('Checkout did not reach the screening lock');
           /* Same atomic repricing writes as set_showtime_price, controlled here
              to force the timing. TC40/TC43 exercise the real staff endpoint. */
           exec_sql('UPDATE showtimes SET base_price=base_price+5 WHERE showtime_id=?',[$st['showtime_id']]);
           exec_sql('UPDATE preference_seats SET seat_price=seat_price+5 WHERE pref_id=?',[$p['pref_id']]);
           exec_sql('UPDATE preferences SET est_total=est_total+5*seat_count WHERE pref_id=?',[$p['pref_id']]);
           $db->commit();
           $code=proc_close($proc); $proc=null;
           if($code!==0) throw new RuntimeException('HTTP worker failed: '.file_get_contents($file.'.err'));
           $response=json_decode(file_get_contents($file),true);
           $after=(int)one('SELECT COUNT(*) n FROM bookings')['n'];
           return [$after===$before && has($response['html'],'price changed') && one('SELECT pref_id FROM preferences WHERE pref_id=?',[$p['pref_id']]),
                   'Booking count '.$before.' -> '.$after.'; changed price reported '.(has($response['html'],'price changed')?'yes':'no')];
       } finally {
           $db->rollback();
           if(is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
           foreach([$file,$file.'.id',$file.'.err'] as $path) if(is_file($path)) unlink($path);
       }
   });
