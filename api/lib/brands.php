<?php
// Marcas que maneja SmartISP (lista enviada por el negocio, 2026-10-28). No se muestran como logos:
// sirven para que la búsqueda reconozca la marca escrita de otra forma y para llms.txt.
const SMARTISP_BRANDS = [
    'A-Data', 'AccessPRO', 'Acronis', 'Actiontec', 'AKG', 'AMD', 'Anker', 'Aorus', 'APC', 'Apple', 'ASUS', 'AudioCodes', 'Avaya', 'Axis',
    'Belkin', 'BenQ', 'Blue Code', 'Canon', 'Case Logic', 'Cisco', 'Codegen', 'Compaq', 'Corsair', 'Cyrus',
    'Dahua', 'Datalogic', 'DBX', 'Dell', 'Dell EMC', 'DJI', 'D-Link', 'Eaton', 'ECS', 'Elo', 'Epson', 'Eufy', 'Extreme', 'EyeSight', 'EZVIZ',
    'Firelite', 'Folksafe', 'Fortinet', 'Forza', 'Gear4', 'Gigabyte',
    'Hanwha', 'Harman Kardon', 'HID', 'Hikvision', 'HiLook', 'Homedics', 'Honeywell', 'House of Marley', 'HP', 'HPE', 'HSI', 'Huawei', 'Hurricane', 'HyperX',
    'Intel', 'Jabra', 'JAM', 'JBL', 'JBL Pro', 'Kaspersky', 'Kingston', 'Kingston Fury', 'Kingston ValueRAM', 'Klip Xtreme',
    'Lenovo', 'LG', 'Linksys', 'Logic Controls', 'Logitech', 'McAfee', 'Meraki', 'Microsoft', 'Mikrotik', 'Mobileye', 'Mophie', 'Motorola', 'MSI',
    'NCR', 'Nebula', 'Newland', 'Nexxt Solutions', 'Nokia', 'Norton', 'NZXT', 'Oura', 'Panduit', 'Poly', 'Primus Gaming', 'QNAP',
    'Razer', 'RBH', 'Ricoh', 'Riverbed', 'Ruckus', 'Samsung', 'SanDisk', 'Screenbeam', 'Seagate', 'Seco-Larm', 'Sewoo', 'SonicWall', 'Sony',
    'Soundcore', 'Soundcraft', 'Star', 'Starlink', 'StarTech.com', 'TCL', 'Therabody', 'Thule', 'Toshiba', 'TP-Link', 'TSC',
    'Ubiquiti', 'Unitech', 'ViewSonic', 'Vitek', 'VNA', 'VTech', 'Wacom', 'Western Digital', 'Xerox', 'Xiaomi', 'Xtech', 'Zagg', 'Zebra', 'ZKTeco',
];

function smartispBrandCompact(string $text): string {
    return str_replace('-', '', smartispSlug($text, 120, ''));
}

// Forma compacta de la marca si la búsqueda es exactamente una marca («dlink», «tp link», «Hyper X»).
function smartispBrandSearchKey(string $search): string {
    $key = smartispBrandCompact($search);
    if (strlen($key) < 2) return '';
    foreach (SMARTISP_BRANDS as $brand) {
        if (smartispBrandCompact($brand) === $key) return $key;
    }
    return '';
}
