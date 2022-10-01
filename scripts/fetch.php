<?php

$curl = curl_init();
curl_setopt($curl,CURLOPT_RETURNTRANSFER,1);
curl_setopt($curl,CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($curl, CURLOPT_ENCODING, '');

function build_path($uri) {
    $parts = parse_url($uri);

    return "data/{$parts['host']}{$parts['path']}";
}

function build_version_path($uri) {
    $parts = parse_url($uri);

    [,, $version, $file] = explode('/', $parts['path']);

    return "schema/{$version}/{$file}";
}

libxml_set_external_entity_loader(function($publicId, $systemId) use ($curl) {
    print "$publicId\n";

    if (preg_match('/^data\/(.+)/', $systemId, $matches)) {
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
    '1.0' => '20120330',
    '1.1d1' => '20130915',
    '1.1d2' => '20140930',
    '1.1d3' => '20150301',
    '1.1' => '20151215',
    '1.2d1' => '20170631',
    '1.2d2' => '20180401',
    '1.2' => '20190208',
    '1.3d1' => '20190831',
    '1.3d2' => '20201130',
    '1.3' => '20210610',
];

$suffixes = [
    '1.3d2' => '1-3d2',
    '1.3' => '1-3',
];

$files = [
    'archiving' => [
        'archivearticle1' => 'Journal Archiving and Interchange DTD',
        'archivearticle1-mathml3' => 'Journal Archiving and Interchange DTD with MathML3',
        'archive-oasis-article1' => 'Journal Archiving and Interchange DTD with OASIS Tables',
        'archive-oasis-article1-mathml3' => 'Journal Archiving and Interchange DTD with OASIS Tables with MathML3'
    ],
    'articleauthoring' => [
        'articleauthoring1' => 'Article Authoring DTD',
        'articleauthoring1-mathml3' => 'Article Authoring DTD with MathML3',
    ],
    'publishing' => [
        'journalpublishing1' => 'Journal Publishing DTD',
        'journalpublishing1-mathml3' => 'Journal Publishing DTD with MathML3',
        'journalpublishing-oasis-article1' => 'Journal Publishing DTD with OASIS Tables',
        'journalpublishing-oasis-article1-mathml3' => 'Journal Publishing DTD with OASIS Tables with MathML3'
    ]
];

$ignore = [
    'http://jats.nlm.nih.gov/archiving/1.0/JATS-archivearticle1-mathml3.dtd',
    'http://jats.nlm.nih.gov/archiving/1.0/JATS-archive-oasis-article1-mathml3.dtd',
    'http://jats.nlm.nih.gov/articleauthoring/1.0/JATS-articleauthoring1-mathml3.dtd',
    'http://jats.nlm.nih.gov/publishing/1.0/JATS-journalpublishing1-mathml3.dtd',
    'http://jats.nlm.nih.gov/publishing/1.0/JATS-journalpublishing-oasis-article1-mathml3.dtd'
];

foreach ($files as $colour => $names) {
    foreach ($versions as $version => $date) {
        foreach ($names as $name => $title) {
            if (array_key_exists($version, $suffixes)) {
                $name = str_replace('1', $suffixes[$version], $name);
            }

            $publicId = "-//NLM//DTD JATS (Z39.96) {$title} v{$version} {$date}//EN";
            $systemId = "http://jats.nlm.nih.gov/{$colour}/{$version}/JATS-{$name}.dtd";

            if (in_array($systemId, $ignore)) {
                continue;
            }

            $xml = <<<XML
<!DOCTYPE article PUBLIC "$publicId" "$systemId">
<article>
    <front>
        <journal-meta>
            <journal-id/>
            <issn/>
        </journal-meta>
        <article-meta>
            <title-group>
                <article-title/>
            </title-group>
            <pub-date>
                <year/>
            </pub-date>
        </article-meta>
    </front>
</article>
XML;
            $doc = new DOMDocument;
            $doc->loadXML($xml, LIBXML_DTDLOAD);
            $doc->validate();

            $systemIds[$publicId] = $systemId;
        }
    }
}

// merge directories and build the catalog

$implementation = new DOMImplementation();
//$dtd = $implementation->createDocumentType('catalog',
//    '-//OASIS//DTD Entity Resolution XML Catalog V1.0//EN',
//    'http://www.oasis-open.org/committees/entity/release/1.0/catalog.dtd');
$catalog = $implementation->createDocument('urn:oasis:names:tc:entity:xmlns:xml:catalog', 'catalog');
$catalog->documentElement->setAttribute('prefer', 'public');

// TODO: remove old data folder

foreach ($systemIds as $publicId => $systemId) {
    $path = build_path($systemId);
    $versionPath = build_version_path($systemId);

    $dir = dirname($path);
    $versionDir = dirname($versionPath);

    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

    foreach ($iterator as $info) {
        if ($info->isFile()) {
            $inputPath = $info->getPathname();
            $relativePath = join('/', array_slice(explode('/', $inputPath), 4));
            $outputPath = "$versionDir/$relativePath";
            $outputDir = dirname($outputPath);

            if (!file_exists($outputDir)) {
                mkdir($outputDir, 0777, true);
            }

            copy($inputPath, $outputPath);
        }
    }

    $entry = $catalog->createElement('public');
    $entry->setAttribute('publicId', $publicId);
    $entry->setAttribute('uri', preg_replace('/^schema\//', '', $versionPath));
    $catalog->documentElement->appendChild($entry);
}

$catalog->encoding = 'utf-8';
$catalog->formatOutput = true;
$catalog->save('schema/catalog.xml');

file_put_contents('schema/doctypes.json', json_encode($systemIds, JSON_PRETTY_PRINT));
