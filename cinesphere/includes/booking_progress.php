<?php
/** $booking_step: 1 seats, 2 short-list, 3 confirmation. */
?>
<nav class="container booking-progress" aria-label="Booking progress">
  <ol>
    <?php foreach ([1 => 'Choose seats', 2 => 'Short-list', 3 => 'Confirmation'] as $number => $label): ?>
      <li <?= $number === $booking_step ? 'aria-current="step"' : '' ?>
          class="<?= $number < $booking_step ? 'complete' : '' ?>">
        <span class="step-number" aria-hidden="true"><?= $number ?></span>
        <span><?= e($label) ?></span>
      </li>
    <?php endforeach; ?>
  </ol>
</nav>
