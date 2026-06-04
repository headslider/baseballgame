#!/bin/sh
cd /virtual/ref/public_html/www.realemotionfactory.com/baseball
/usr/local/bin/php83cli cron_weekly_ranking_runner.php 2>> /virtual/ref/public_html/www.realemotionfactory.com/baseball/scores/cron_weekly_ranking_runner.log
