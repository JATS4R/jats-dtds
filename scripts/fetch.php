<?php

$curl = curl_init();
curl_setopt($curl,CURLOPT_RETURNTRANSFER,1);
curl_setopt($curl,CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($curl, CURLOPT_ENCODING, '');

function build_path($uri) {
    $parts = parse_url($uri);

    return "schema/{$parts['host']}{$parts['path']}";

//    [,, $version, $file] = explode('/', $parts['path']);
//
//    return "schema/{$version}/{$file}";
}

libxml_set_external_entity_loader(function($publicId, $systemId) use ($curl) {
    print "$publicId\n";

    if (preg_match('/^schema\/(.+)/', $systemId, $matches)) {
        $systemId = 'http://' . $matches[1];
    }

    $path = build_path($systemId);

    if (!file_exists($path)) {
        $dir = dirname($path);

        if (!file_exists($dir)) {
            mkdir($dir, 0777, true);
        }

        curl_setopt($curl,CURLOPT_URL, $systemId);

        $data = curl_exec($curl);

        if ($errorCode = curl_errno($curl)) {
            $error = curl_strerror($errorCode);
            throw new Exception("Error fetching $systemId: $error");
        }

        if (!$data) {
            throw new Exception("No data found at $systemId");
        }

        if (!preg_match('/^\s*<!--/', $data)) {
            throw new Exception("Not a DTD at $systemId");
        }

        file_put_contents($path, $data);
    }

    return $path;
});

$versions = [
//    '1.0' => '20120330', // disabled as this version doesn't have all the formats
    '1.1d1' => '20130915',
    '1.1d2' => '20140930',
    '1.1d3' => '20150301',
    '1.1' => '20151215',
    '1.2d1' => '20170631',
    '1.2d2' => '20180401',
    '1.2' => '20190208',
];

$files = [
    'archiving' => [
        'archivearticle1' => 'Journal Archiving and Interchange DTD',
        'archivearticle1-mathml3' => 'Journal Archiving and Interchange DTD with MathML3',
        'archive-oasis-article1' => 'Journal Archiving and Interchange DTD with OASIS Tables',
        'archive-oasis-article1-mathml3' => 'Journal Archiving and Interchange DTD with OASIS Tables with MathML3'
    ],
    'publishing' => [
        'journalpublishing1' => 'Journal Publishing DTD',
        'journalpublishing1-mathml3' => 'Journal Publishing DTD with MathML3',
        'journalpublishing-oasis-article1' => 'Journal Publishing DTD with OASIS Tables',
        'journalpublishing-oasis-article1-mathml3' => 'Journal Publishing DTD with OASIS Tables with MathML3'
    ]
];

$paths = [];

foreach ($files as $colour => $names) {
    foreach ($versions as $version => $date) {
        foreach ($names as $name => $title) {
            $publicId = "-//NLM//DTD JATS (Z39.96) {$title} v{$version} {$date}//EN";
            $systemId = "http://jats.nlm.nih.gov/{$colour}/{$version}/JATS-{$name}.dtd";

            $xml = <<<XML
<!DOCTYPE article PUBLIC "$publicId" "$systemId">
<article/>
XML;
            $doc = new DOMDocument;
            $doc->loadXML($xml, LIBXML_DTDLOAD);
            $doc->validate();

            $paths[$publicId] = build_path($systemId);
        }
    }
}

// build the catalog

$implementation = new DOMImplementation();
$dtd = $implementation->createDocumentType('catalog',
    '-//OASIS//DTD Entity Resolution XML Catalog V1.0//EN',
    'http://www.oasis-open.org/committees/entity/release/1.0/catalog.dtd');
$catalog = $implementation->createDocument('urn:oasis:names:tc:entity:xmlns:xml:catalog', 'catalog', $dtd);
$catalog->documentElement->setAttribute('prefer', 'public');

foreach ($paths as $publicId => $path) {
    $entry = $catalog->createElement('public');
    $entry->setAttribute('publicId', $publicId);
    $entry->setAttribute('uri', $path);
    $catalog->documentElement->appendChild($entry);
}

$catalog->encoding = 'utf-8';
$catalog->formatOutput = true;
$catalog->save('catalog.xml');
