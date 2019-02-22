# Magento 2: Medbuy Customer Registration

## Installation without composer


1. Copy theme to app/code/Medbuy/CustomerRegistration folder.
2. Run 

```bash

cd <magento_root>
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento setup:static-content:deploy
php bin/magento cache:clean 
php bin/magento cache:flush 