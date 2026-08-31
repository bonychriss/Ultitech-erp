#!/bin/bash
# Database Reset Script for Ultimate General Trading Payment Voucher System

echo "🔧 Resetting Ultimate Trading Payment Voucher Database..."

# Colors for output
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${YELLOW}Step 1: Checking XAMPP services...${NC}"
sudo /opt/lampp/lampp status

echo -e "\n${YELLOW}Step 2: Dropping existing database...${NC}"
/opt/lampp/bin/mysql -u root -e "DROP DATABASE IF EXISTS ultimate_trading_vouchers;" 2>/dev/null
if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ Old database removed${NC}"
else
    echo -e "${RED}✗ Failed to remove old database${NC}"
fi

echo -e "\n${YELLOW}Step 3: Creating new database...${NC}"
/opt/lampp/bin/mysql -u root -e "CREATE DATABASE ultimate_trading_vouchers;"
if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ Database created successfully${NC}"
else
    echo -e "${RED}✗ Failed to create database${NC}"
    exit 1
fi

echo -e "\n${YELLOW}Step 4: Importing schema and data...${NC}"
/opt/lampp/bin/mysql -u root ultimate_trading_vouchers < "/opt/lampp/htdocs/payment-voucher-system/database_setup.sql"
if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ Schema and data imported successfully${NC}"
else
    echo -e "${RED}✗ Failed to import schema${NC}"
    exit 1
fi

echo -e "\n${YELLOW}Step 5: Verifying setup...${NC}"
TABLES=$(/opt/lampp/bin/mysql -u root -e "USE ultimate_trading_vouchers; SHOW TABLES;" 2>/dev/null | wc -l)
if [ $TABLES -gt 4 ]; then
    echo -e "${GREEN}✓ All tables created successfully${NC}"
else
    echo -e "${RED}✗ Not all tables were created${NC}"
    exit 1
fi

USERS=$(/opt/lampp/bin/mysql -u root -e "USE ultimate_trading_vouchers; SELECT COUNT(*) FROM users;" 2>/dev/null | tail -n 1)
if [ $USERS -ge 5 ]; then
    echo -e "${GREEN}✓ Sample users created successfully${NC}"
else
    echo -e "${RED}✗ Sample users not created properly${NC}"
    exit 1
fi

echo -e "\n${GREEN}🎉 Database setup complete!${NC}"
echo -e "\n${YELLOW}You can now access:${NC}"
echo "• Login Page: http://localhost/payment-voucher-system/login.php"
echo "• Test Page: http://localhost/payment-voucher-system/test.php"
echo "• Navigation: http://localhost/ultimate-trading-nav.html"
echo ""
echo -e "${YELLOW}Default Login Credentials:${NC}"
echo "Admin: admin / admin123"
echo "Employee: maureen / password123"
echo ""