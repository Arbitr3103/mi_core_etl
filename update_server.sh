#!/bin/bash
ssh -t vladimir@178.72.129.61 << 'EOF'
echo 'K9@xN2#vR6*qYmL4p' | sudo -S cp ~/demo_dashboard.php /var/www/html/demo_dashboard.php
echo 'K9@xN2#vR6*qYmL4p' | sudo -S chown www-data:www-data /var/www/html/demo_dashboard.php
ls -la /var/www/html/demo_dashboard.php
EOF
