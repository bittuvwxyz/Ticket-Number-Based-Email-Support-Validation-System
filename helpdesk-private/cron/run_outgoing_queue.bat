@echo off
:: Windows Task Scheduler Batch Launcher for Helpdesk Outgoing Queue
cd /d "C:\xampp\htdocs\helpdesk-private"
"C:\xampp\php\php.exe" -f "C:\xampp\htdocs\helpdesk-private\cron\process_outgoing_queue.php"
