<?php
// Marcas que maneja SmartISP (lista enviada por el negocio, 2026-10-28, más las que trae el catálogo). No se muestran como logos:
// sirven para que la búsqueda reconozca la marca escrita de otra forma y para llms.txt.
const SMARTISP_BRANDS = [
    'A-Data', 'Ablerex', 'AccessPRO', 'Acer', 'Acronis', 'Acros', 'Actiontec', 'Advance', 'Agiler', 'AKG', 'Amazon', 'AMD', 'Anker',
    'Antec', 'AOC', 'Aorus', 'APC', 'Apple', 'Artecma', 'ASUS', 'Audiobox', 'AudioCodes', 'Avaya', 'Axis', 'Belkin', 'BenQ', 'Biwin', 'Blaupunkt',
    'Blue Code', 'Bluetti', 'Boetech', 'Broadcom', 'Brother', 'Canon', 'Case Logic', 'CDP', 'Cisco', 'CNet', 'Codegen', 'Compaq',
    'Cooler Master', 'Corsair', 'Crucial', 'Cyrus', 'D-Link', 'Dahua', 'Datalogic', 'DBX', 'Dell', 'Dell EMC', 'Denwa', 'DJI', 'DuroMax',
    'DuroStar', 'Eaton', 'EcoFlow', 'ECS', 'Elo', 'ENV', 'Epson', 'Eufy', 'Extreme', 'EyeSight', 'EZVIZ', 'Firelite', 'Folksafe',
    'Fortinet', 'Forza', 'Gear4', 'Genius', 'Gigabyte', 'Hanwha', 'Harman Kardon', 'HID', 'Hikvision', 'HiLook', 'Homedics', 'Honeywell',
    'Honor', 'House of Marley', 'HP', 'HPE', 'HSI', 'Huawei', 'Hurricane', 'HyperX', 'Indurama', 'Infinix', 'Intel', 'Intex', 'Jabra',
    'JAM', 'JBL', 'JBL Pro', 'Kaspersky', 'Kingston', 'Kingston Fury', 'Kingston ValueRAM', 'Klip Xtreme', 'Lenovo', 'Lexar', 'Lexmark',
    'LG', 'Linksys', 'Logic Controls', 'Logitech', 'McAfee', 'Meraki', 'Mercusys', 'Mibro', 'Microsoft', 'Mikrotik', 'Mobileye', 'Mophie',
    'Motorola', 'MSI', 'NCR', 'Nebula', 'Newland', 'Nexxt Solutions', 'Nintendo', 'Nokia', 'Norton', 'NZXT', 'Oura', 'Panduit', 'PNY',
    'Poly', 'Power Joe', 'Primus Gaming', 'Pulsar', 'QNAP', 'Razer', 'RBH', 'Ricoh', 'Riverbed', 'Ruckus', 'Samsung', 'SanDisk', 'SAT',
    'Screenbeam', 'Seagate', 'Seco-Larm', 'Sewoo', 'SonicWall', 'Sony', 'Soundcore', 'Soundcraft', 'Speedmind', 'Star', 'Starlink',
    'StarTech.com', 'TCL', 'Teros', 'Therabody', 'Thule', 'Timetec', 'Toshiba', 'TP-Link', 'Tripp Lite', 'TSC', 'Ubiquiti', 'Unipower',
    'Unitech', 'Verbatim', 'ViewSonic', 'Vitek', 'VNA', 'VTech', 'Wacom', 'Western Digital', 'Whirlpool', 'Xerox', 'Xiaomi', 'Xtech',
    'Zagg', 'Zebra', 'ZKTeco', '3nStar',
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
