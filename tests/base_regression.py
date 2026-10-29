"""Base-version regression tests. Creates and drops only its own isolated test DB.
Requires portable local PHP/MariaDB and the local Mailpit inbox; no third-party Python packages.
Run: python tests/base_regression.py
"""
from pathlib import Path
import datetime as dt
import http.cookiejar
import json
import os
import re
import subprocess
import time
import urllib.parse
import urllib.request
import uuid

REPO = Path(__file__).resolve().parents[1]
RUNTIME = REPO.parent / 'CineSphere-local'
PHP = RUNTIME / 'php/php.exe'
MYSQL = RUNTIME / 'mariadb-11.4.10-winx64/bin/mariadb.exe'
DB = 'cinesphere_test_' + uuid.uuid4().hex[:12]
PORT = 18087
BASE = f'http://127.0.0.1:{PORT}/cinesphere/'
RESULTS = []
SERVERS = []
FLAGS = getattr(subprocess, 'CREATE_NO_WINDOW', 0)

def sql(query, database=True):
    args = [str(MYSQL), '--no-defaults', '--host=127.0.0.1', '--user=root', '--batch', '--skip-column-names']
    if database: args.append(DB)
    p = subprocess.run(args, input=query, text=True, encoding='utf-8', capture_output=True, creationflags=FLAGS)
    if p.returncode: raise RuntimeError(p.stderr)
    return p.stdout.strip()

def check(name, condition, detail=''):
    RESULTS.append({'test': name, 'pass': bool(condition), 'detail': detail})
    print(('PASS' if condition else 'FAIL') + ': ' + name, flush=True)
    if not condition: raise AssertionError(name + ' ' + detail)

class Client:
    def __init__(self, base=BASE):
        self.base = base
        self.http = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token = ''
        self.html = ''
    def get(self, page): return self.request(page)
    def post(self, page, data):
        return self.request(page, dict({'csrf': self.token}, **data))
    def request(self, page, data=None):
        payload = urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None
        with self.http.open(self.base + page, data=payload, timeout=15) as r:
            self.url = r.url
            self.html = r.read().decode('utf-8')
        assert not re.search(r'(Fatal error|<b>Warning|<b>Deprecated)', self.html), self.html[:300]
        token = re.search(r'name="csrf" value="([^"]+)"', self.html)
        if token: self.token = token[1]
        return self.html
    def login(self, email='demo@cinesphere.sg', password='Demo@123'):
        self.get('account.php')
        h=self.post('process_login.php', {'email':email,'password':password})
        assert 'Signed in as ' + email in h
    def add(self, showtime, seat, native=False):
        self.get(f'booking.php?showtime={showtime}')
        self.post('process_preference.php', {'showtime_id':showtime, ('seats[]' if native else 'seat_ids'): [seat] if native else str(seat)})
    def confirm(self, pref, gateway='success'):
        self.get('preferences.php')
        quote = re.search(r'name="quote" value="([^"]+)"', self.html).group(1)
        return self.post('process_booking.php', {'confirm[]':pref,'agree':'1','gateway':gateway,'quote':quote})

def start(port, smtp_port=None):
    env = dict(os.environ, CINESPHERE_DB_NAME=DB)
    args=[str(PHP),'-c',str(RUNTIME/'php.ini')]
    if smtp_port: args += ['-d',f'smtp_port={smtp_port}']
    args += ['-S',f'127.0.0.1:{port}','-t',str(REPO)]
    log=open(RUNTIME/f'logs/base-tests-{port}.log','w')
    p=subprocess.Popen(args, env=env, stdout=log, stderr=log, creationflags=FLAGS)
    SERVERS.append((p,log))
    for _ in range(40):
        if p.poll() is not None: raise RuntimeError('Test server exited')
        try:
            urllib.request.urlopen(f'http://127.0.0.1:{port}/cinesphere/',timeout=1).close(); return
        except OSError: time.sleep(.1)
    raise RuntimeError('Test server not ready')

def pref(user=2): return int(sql(f'SELECT MAX(pref_id) FROM preferences WHERE user_id={user}'))
def booking(user=2): return int(sql(f"SELECT MAX(booking_id) FROM bookings WHERE user_id={user} AND booking_status='confirmed'"))
def mail_count():
    return json.load(urllib.request.urlopen('http://127.0.0.1:8025/api/v1/messages'))['total']

def run():
    schema=(REPO/'cinesphere/sql/01_schema.sql').read_text()
    assert re.fullmatch(r'cinesphere_test_[a-f0-9]{12}', DB)
    sql(schema.replace('cinesphere;',DB+';').replace('CREATE DATABASE cinesphere','CREATE DATABASE '+DB),False)
    sql((REPO/'cinesphere/sql/02_seed.sql').read_text().replace('USE cinesphere;',f'USE {DB};'))
    sql("UPDATE movies SET title=CONCAT('[Base test] ',title)")
    # Fixture is in the future, independent of today's seeded schedule.
    date=sql("SELECT DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 10 DAY), '%Y-%m-%d')")
    sql(f"INSERT INTO showtimes(movie_id,hall_id,show_date,show_time,format,base_price) VALUES(1,1,'{date}','12:00:00','2D',12)")
    show=int(sql('SELECT MAX(showtime_id) FROM showtimes'))
    seats=list(map(int,sql('SELECT seat_id FROM seats WHERE hall_id=1 ORDER BY seat_id LIMIT 5').splitlines()))
    start(PORT)
    a=Client(); a.login(); b=Client(); b.login('siti@cinesphere.sg'); admin=Client();admin.login('admin@cinesphere.sg','Admin@123')
    check('Customer and staff sign-in',True)
    before=mail_count()
    a.add(show,seats[0],native=True); p=pref()
    check('No-JavaScript checkbox submission adds a short-list choice', 'Added to your short-list' in a.html)
    h=a.confirm(p,'declined')
    check('Declined payment keeps preference and allocates no seat',sql(f'SELECT COUNT(*) FROM preferences WHERE pref_id={p}')=='1' and sql(f'SELECT COUNT(*) FROM booking_seats WHERE showtime_id={show}')=='0')
    h=a.confirm(p); bid=booking()
    check('Approved payment creates ticket and history snapshot', 'Your booking is confirmed' in h and sql(f'SELECT COUNT(*) FROM booking_seat_history WHERE booking_id={bid}')=='1')
    check('Acknowledgement accepted by local SMTP inbox', sql(f'SELECT email_sent FROM bookings WHERE booking_id={bid}')=='1' and mail_count()>before)
    check('All delivered messages target only the local coursework mailbox',all(all(t['Address']=='coursework@cinesphere.local' for t in m['To']) for m in json.load(urllib.request.urlopen('http://127.0.0.1:8025/api/v1/messages'))['messages']))
    a.post('cancel_booking.php',{'booking_id':bid})
    check('Cancellation refunds, releases seat and preserves original seat history',sql(f"SELECT CONCAT(booking_status,':',payment_status) FROM bookings WHERE booking_id={bid}")=='cancelled:refunded' and sql(f'SELECT COUNT(*) FROM booking_seats WHERE booking_id={bid}')=='0' and sql(f'SELECT COUNT(*) FROM booking_seat_history WHERE booking_id={bid}')=='1')
    check('Cancelled history still shows A1', 'A1' in a.html)
    ref=sql(f'SELECT booking_ref FROM bookings WHERE booking_id={bid}')
    a.get('confirmation.php?ref='+ref)
    check('Cancelled reference cannot render a valid confirmation ticket', 'Your booking is confirmed' not in a.html)
    a.add(show,seats[1]);pa=pref();b.add(show,seats[1]);pb=pref(3)
    a.confirm(pa);b.confirm(pb)
    check('Stale second customer cannot buy the same seat',sql(f'SELECT COUNT(*) FROM booking_seats WHERE showtime_id={show} AND seat_id={seats[1]}')=='1' and 'None of the entries you selected are still available' in b.html)
    a.get('admin.php');check('Customer cannot access staff page', 'restricted to CineSphere staff' in a.html)
    # A late screening overlaps the next calendar day.
    late=sql("SELECT DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 12 DAY), '%Y-%m-%d')")
    nxt=sql("SELECT DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 13 DAY), '%Y-%m-%d')")
    def schedule(date,time):
        admin.get('admin.php')
        return admin.post('admin_action.php',{'action':'add_showtime','movie_id':1,'hall_id':1,'show_date':date,'show_time':time,'format':'2D','base_price':12})
    schedule(late,'23:30'); lateid=int(sql('SELECT MAX(showtime_id) FROM showtimes'))
    h=schedule(nxt,'00:30');check('Cross-midnight overlap rejected', 'hall is busy' in h)
    h=schedule(nxt,'01:57');check('Turnaround overlap rejected', 'hall is busy' in h)
    h=schedule(nxt,'01:58');check('Exact turnaround boundary accepted', 'scheduled in' in h)
    admin.post('admin_action.php',{'action':'cancel_showtime','showtime_id':lateid})
    h=schedule(nxt,'00:30');check('Reverse cross-midnight overlap rejected', 'hall is busy' in h)
    # Replacement overlaps the cancelled original but not 01:58 screening.
    h=schedule(late,'23:29');check('Replacement after cancellation accepted', 'scheduled in' in h)
    h=admin.post('admin_action.php',{'action':'reopen_showtime','showtime_id':lateid})
    check('Reopening a conflicting cancelled screening rejected', 'hall is busy' in h and sql(f'SELECT status FROM showtimes WHERE showtime_id={lateid}')=='cancelled')
    # Force a write failure to prove staff cancellation rolls back all DB changes.
    sql("CREATE TRIGGER test_cancel_failure BEFORE UPDATE ON bookings FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Test failure';")
    h=admin.post('admin_action.php',{'action':'cancel_showtime','showtime_id':show})
    check('Staff cancellation rolls back if any write fails',sql(f'SELECT status FROM showtimes WHERE showtime_id={show}')=='open' and sql(f'SELECT COUNT(*) FROM booking_seats WHERE showtime_id={show}')=='1')
    sql('DROP TRIGGER test_cancel_failure')
    before=mail_count();h=admin.post('admin_action.php',{'action':'cancel_showtime','showtime_id':show})
    check('Staff cancellation releases seats, keeps history and sends notice', sql(f'SELECT COUNT(*) FROM booking_seats WHERE showtime_id={show}')=='0' and 'Cancellation notices delivered' in h and mail_count()>before)
    # Failed delivery must not claim success or invalidate a paid booking.
    start(PORT+1,65025)
    fail=Client(f'http://127.0.0.1:{PORT+1}/cinesphere/');fail.login()
    open_show=int(sql("SELECT MAX(showtime_id) FROM showtimes WHERE status='open'"))
    fail.add(open_show,seats[3]);h=fail.confirm(pref());bid=booking()
    check('Mail failure leaves confirmed ticket but reports delivery failure', sql(f'SELECT email_sent FROM bookings WHERE booking_id={bid}')=='0' and 'could not be delivered' in h and 'Acknowledgements have been delivered' not in h)
    print(f'{len(RESULTS)} regression checks passed.',flush=True)

try:
    run()
finally:
    for process,log in SERVERS:
        process.terminate();process.wait(timeout=10);log.close()
    # DB identifier is generated by this script, never supplied by the user.
    if re.fullmatch(r'cinesphere_test_[a-f0-9]{12}',DB): sql(f'DROP DATABASE IF EXISTS {DB}',False)
    report={'at':dt.datetime.now(dt.timezone.utc).isoformat(),'isolated_database':DB,'checks':RESULTS}
    (REPO/'tests/base-results.json').write_text(json.dumps(report,indent=2),encoding='utf-8')
