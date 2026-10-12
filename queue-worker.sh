#!/bin/bash

LOG="/home/u576088723/domains/hasim.online/public_html/storage/logs/queue-cron.log"
PHP="/opt/alt/php83/usr/bin/php"
ARTISAN="/home/u576088723/domains/hasim.online/public_html/artisan"

echo "===== $(date '+%Y-%m-%d %H:%M:%S') CRON START =====" >> "$LOG"

$PHP $ARTISAN queue:work --once --queue=inbound -vvv >> "$LOG" 2>&1
$PHP $ARTISAN queue:work --once --queue=outbound -vvv >> "$LOG" 2>&1
$PHP $ARTISAN queue:work --once --queue=media -vvv >> "$LOG" 2>&1
$PHP $ARTISAN queue:work --once --queue=ai -vvv >> "$LOG" 2>&1

echo "===== $(date '+%Y-%m-%d %H:%M:%S') CRON END =====" >> "$LOG"