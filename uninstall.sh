#!/bin/bash
#
# BackBork KISS - Uninstallation Script
# Disaster Recovery Plugin for WHM
#
# Copyright (c) The Network Crew Pty Ltd & Velocity Host Pty Ltd
# https://github.com/The-Network-Crew/BackBork-KISS-Plugin-for-WHM
#

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}"
echo "╔═══════════════════════════════════════════════════════╗"
echo "║             BackBork KISS Uninstaller                 ║"
echo "║          Disaster Recovery Plugin for WHM             ║"
echo "╚═══════════════════════════════════════════════════════╝"
echo -e "${NC}"

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    echo -e "${RED}Error: This script must be run as root${NC}"
    exit 1
fi

# Confirm uninstallation
echo -e "${YELLOW}WARNING: This will remove BackBork KISS and all its configuration.${NC}"
echo ""
read -p "Do you want to keep your configuration and logs? (y/n): " KEEP_CONFIG
read -p "Are you sure you want to uninstall BackBork KISS? (y/n): " CONFIRM

if [ "$CONFIRM" != "y" ] && [ "$CONFIRM" != "Y" ]; then
    echo -e "${BLUE}Uninstallation cancelled.${NC}"
    exit 0
fi

echo ""
echo -e "${YELLOW}Starting uninstallation...${NC}"

# Define paths
WHM_CGI_DIR="/usr/local/cpanel/whostmgr/docroot/cgi"
APPS_DIR="/var/cpanel/apps"
CONFIG_DIR="/usr/local/cpanel/3rdparty/backbork"
ICON_DIR="/usr/local/cpanel/whostmgr/docroot/addon_plugins"
CRON_FILE="/etc/cron.d/backbork"

# Unregister from AppConfig
echo -e "${BLUE}Unregistering plugin from AppConfig...${NC}"
if [ -f "${APPS_DIR}/backbork.conf" ]; then
    /usr/local/cpanel/bin/unregister_appconfig "${APPS_DIR}/backbork.conf" 2>/dev/null || true
    rm -f "${APPS_DIR}/backbork.conf"
    echo -e "${GREEN}  ✓ AppConfig unregistered${NC}"
fi

# Remove cron job
echo -e "${BLUE}Removing cron job...${NC}"
if [ -f "${CRON_FILE}" ]; then
    rm -f "${CRON_FILE}"
    echo -e "${GREEN}  ✓ Cron job removed${NC}"
fi

# Remove plugin files
echo -e "${BLUE}Removing plugin files...${NC}"
if [ -d "${WHM_CGI_DIR}/backbork" ]; then
    rm -rf "${WHM_CGI_DIR}/backbork"
    echo -e "${GREEN}  ✓ Plugin files removed${NC}"
fi

# Remove icons
echo -e "${BLUE}Removing plugin icons...${NC}"
if [ -f "${ICON_DIR}/backbork.svg" ]; then
    rm -f "${ICON_DIR}/backbork.svg"
fi
if [ -f "${ICON_DIR}/backbork.png" ]; then
    rm -f "${ICON_DIR}/backbork.png"
fi
echo -e "${GREEN}  ✓ Icons removed${NC}"

# Handle configuration
if [ "$KEEP_CONFIG" = "y" ] || [ "$KEEP_CONFIG" = "Y" ]; then
    echo -e "${BLUE}Keeping configuration and logs in ${CONFIG_DIR}${NC}"
    echo -e "${YELLOW}  Note: You can manually remove this directory later with:${NC}"
    echo "  rm -rf ${CONFIG_DIR}"
else
    echo -e "${BLUE}Removing configuration and logs...${NC}"
    if [ -d "${CONFIG_DIR}" ]; then
        rm -rf "${CONFIG_DIR}"
        echo -e "${GREEN}  ✓ Configuration removed${NC}"
    fi
fi

# Restart cpsrvd to apply changes
echo -e "${BLUE}Restarting cpsrvd service...${NC}"
/usr/local/cpanel/scripts/restartsrv_cpsrvd

echo ""
echo -e "${GREEN}╔═══════════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║        BackBork KISS has been uninstalled successfully    ║${NC}"
echo -e "${GREEN}╚═══════════════════════════════════════════════════════════╝${NC}"
echo ""

if [ "$KEEP_CONFIG" = "y" ] || [ "$KEEP_CONFIG" = "Y" ]; then
    echo -e "${YELLOW}Configuration preserved at: ${CONFIG_DIR}${NC}"
fi

echo ""
echo "Thank you for using BackBork KISS!"
echo "https://github.com/The-Network-Crew/BackBork-KISS-Plugin-for-WHM"
echo ""
