<?php
declare(strict_types=1);

/** A szimulált ERP törzsadata. Kitalált márkák és termékek, valós cégekhez nincs köze. [erp_id, sku, név, kategória, márka, EAN, bruttó ár, készlet, leírás] */
$rows = [
    ['E1001', 'URH-100',  'Kézi URH adóvevő 5 W',         'Rádió',            'Vetron',  '5990000000011', 45900, 12, 'Kézi URH adóvevő, 16 csatorna, 5 W teljesítmény, cserélhető akkumulátor.'],
    ['E1002', 'URH-200',  'Kézi URH adóvevő 8 W, IP67',   'Rádió',            'Vetron',  '5990000000028', 74900,  6, 'Vízálló (IP67) kézi adóvevő, 8 W, 32 csatorna, zajszűrő.'],
    ['E1003', 'URH-PAIR', 'URH adóvevő pár, 2 db',        'Rádió',            'Nordcom', '5990000000035', 39900, 20, 'Két darabos szett, töltőállvánnyal, hatótávolság nyílt terepen kb. 5 km.'],
    ['E1004', 'URH-CAR',  'Autós URH rádió',              'Rádió',            'Nordcom', '5990000000042', 62900,  4, 'Fix beépíthető autós rádió, 25 W, mikrofonnal.'],
    ['E1005', 'DECT-10',  'Asztali DECT telefon',         'Telefon',          'Optilink','5990000000059', 18900, 40, 'Vezeték nélküli asztali telefon, hívóazonosítás, 200 névjegy.'],
    ['E1006', 'DECT-20',  'DECT telefon, 2 kézibeszélő',  'Telefon',          'Optilink','5990000000066', 27900, 15, 'Két kézibeszélős DECT szett, üzenetrögzítővel.'],
    ['E1007', 'VOIP-30',  'VoIP asztali telefon',         'Telefon',          'Nordcom', '5990000000073', 32900,  9, 'SIP-kompatibilis asztali telefon, PoE táplálás, színes kijelző.'],
    ['E1008', 'VOIP-40',  'VoIP konferencia telefon',     'Telefon',          'Nordcom', '5990000000080', 119000, 3, 'Konferenciahívásokhoz, 360 fokos mikrofon, USB és Bluetooth.'],
    ['E1009', 'ANT-OM',   'Rúdantenna 2,4 GHz',           'Antenna és kábel', 'Vetron',  '5990000000097',  8900, 35, 'Kültéri rúdantenna, 2,4 GHz, 8 dBi nyereség.'],
    ['E1010', 'ANT-YAGI', 'Irányított antenna 700-2600 MHz','Antenna és kábel','Vetron',  '5990000000103', 14900, 18, 'Irányított LTE antenna, SMA csatlakozó.'],
    ['E1011', 'CAB-10',   'Antennakábel 10 m',            'Antenna és kábel', 'Optilink','5990000000110',  3200, 150, 'Koaxiális kábel, 10 m, N csatlakozó.'],
    ['E1012', 'CAB-20',   'Antennakábel 20 m',            'Antenna és kábel', 'Optilink','5990000000127',  5900, 90, 'Koaxiális kábel, 20 m, N csatlakozó.'],
    ['E1013', 'FIB-3',    'Optikai patch kábel 3 m',      'Antenna és kábel', 'Optilink','5990000000134',  2400,  0, 'Duplex LC-LC patch kábel, 3 m, egymódusú.'],
    ['E1014', 'FIB-10',   'Optikai patch kábel 10 m',     'Antenna és kábel', 'Optilink','5990000000141',  3900, 60, 'Duplex LC-LC patch kábel, 10 m, egymódusú.'],
    ['E1015', 'BOOST-1',  'Mobil jelerősítő, lakás',      'Jelerősítő',       'Nordcom', '5990000000158', 89000,  5, 'Mobilhálózati jelerősítő lakásokhoz, belső és külső antennával.'],
    ['E1016', 'BOOST-2',  'Mobil jelerősítő, iroda',      'Jelerősítő',       'Nordcom', '5990000000165', 149000, 2, 'Nagyobb irodákhoz, több frekvenciasáv, felügyeleti port.'],
    ['E1017', 'BOOST-3',  'Jelerősítő tápegység',         'Jelerősítő',       'Nordcom', '5990000000172',  6900, 25, 'Pótló tápegység a jelerősítőkhöz, 12 V.'],
    ['E1018', 'HS-10',    'Vezetékes fejhallgató mikrofonnal','Headset',      'Optilink','5990000000189',  9900, 55, 'Irodai headset, USB, zajszűrős mikrofon.'],
    ['E1019', 'HS-20',    'Vezeték nélküli headset',      'Headset',          'Optilink','5990000000196', 29900, 14, 'Bluetooth headset, 12 órás üzemidő, töltődokkal.'],
    ['E1020', 'HS-RADIO', 'Hangosbeszélő-mikrofon URH-hoz','Headset',         'Vetron',  '5990000000202',  7900, 30, 'Vállra csíptethető hangosbeszélő mikrofon kézi adóvevőkhöz.'],
    ['E1021', 'CHG-URH',  'Töltőállvány URH-hoz',         'Rádió',            'Vetron',  '5990000000219',  6900, 22, 'Asztali töltőállvány a Vetron kézi adóvevőkhöz.'],
    ['E1022', 'BAT-URH',  'Pótakkumulátor URH-hoz',       'Rádió',            'Vetron',  '5990000000226',  8900, 17, 'Li-ion pótakkumulátor, 2000 mAh.'],
    ['E1023', 'MNT-WALL', 'Falikonzol antennához',        'Antenna és kábel', 'Nordcom', '5990000000233',  2900, 70, 'Rozsdamentes falikonzol, 40 cm kinyúlás.'],
    ['E1024', 'SW-8',     'Hálózati switch 8 port',       'Hálózat',          'Optilink','5990000000240', 12900, 11, 'Nem menedzselhető gigabites switch, 8 port, fémházas.'],
];

$products = [];
foreach ($rows as $r) {
    $products[] = [
        'erp_id' => $r[0], 'sku' => $r[1], 'name' => $r[2], 'category' => $r[3], 'brand' => $r[4],
        'ean' => $r[5], 'price' => $r[6], 'stock' => $r[7], 'description' => $r[8],
        'active' => true, 'updated_at' => '2026-10-01 08:00:00',
    ];
}
return $products;
