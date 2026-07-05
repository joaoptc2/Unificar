<div class="page-header">
    <h1><i class="bi bi-calendar3 me-2"></i>Calendário</h1>
    <div class="d-flex gap-2">
        <a href="index.php?m=chat&page=meetings" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-list me-1"></i> Lista
        </a>
        <a href="index.php?m=chat&page=meetings&action=create" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i> Agendar
        </a>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <a href="index.php?m=chat&page=meetings&action=calendar&month=<?= $prevMonth ?>&year=<?= $prevYear ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-chevron-left"></i>
        </a>
        <h5 class="mb-0">
            <?php
            $months = ['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
            echo $months[$month - 1] . ' ' . $year;
            ?>
        </h5>
        <a href="index.php?m=chat&page=meetings&action=calendar&month=<?= $nextMonth ?>&year=<?= $nextYear ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-chevron-right"></i>
        </a>
    </div>
    <div class="card-body p-0">
        <table class="calendar-table">
            <thead>
                <tr>
                    <th>Dom</th><th>Seg</th><th>Ter</th><th>Qua</th><th>Qui</th><th>Sex</th><th>Sáb</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $firstDay = mktime(0, 0, 0, $month, 1, $year);
                $daysInMonth = (int) date('t', $firstDay);
                $startWeekday = (int) date('w', $firstDay);
                $today = date('Y-m-d');
                $day = 1;
                $rows = ceil(($daysInMonth + $startWeekday) / 7);

                for ($row = 0; $row < $rows; $row++):
                ?>
                <tr>
                    <?php for ($col = 0; $col < 7; $col++):
                        $cellIdx = $row * 7 + $col;
                        if ($cellIdx < $startWeekday || $day > $daysInMonth):
                    ?>
                        <td class="calendar-cell calendar-empty"></td>
                    <?php else:
                        $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
                        $isToday = ($dateStr === $today);
                        $dayMeetings = $meetingsByDay[$day] ?? [];
                    ?>
                        <td class="calendar-cell <?= $isToday ? 'calendar-today' : '' ?>">
                            <div class="calendar-day-num"><?= $day ?></div>
                            <?php foreach ($dayMeetings as $m): ?>
                            <a href="index.php?m=chat&page=meetings&action=show&id=<?= $m['id'] ?>"
                               class="calendar-event calendar-event-<?= $m['type'] ?? 'video' ?>"
                               title="<?= Sanitize::e($m['title']) ?>">
                                <?= date('H:i', strtotime($m['scheduled_at'])) ?> <?= Sanitize::e(mb_substr($m['title'], 0, 20)) ?>
                            </a>
                            <?php endforeach; ?>
                        </td>
                    <?php $day++; endif; endfor; ?>
                </tr>
                <?php endfor; ?>
            </tbody>
        </table>
    </div>
</div>
