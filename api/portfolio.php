<?php
/**
 * API pública: devuelve las obras publicadas y las categorías.
 * La consume el portafolio (index.html).
 */
require_once __DIR__ . '/../inc/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_out(array('ok' => false, 'error' => 'Método no permitido.'), 405);
}

$photos = photos_all();
$public = array();

foreach ($photos as $p) {
    if (empty($p['src'])) {
        continue; // las obras sin imagen no se publican
    }
    $public[] = array(
        'id'            => isset($p['id']) ? $p['id'] : '',
        'code'          => isset($p['code']) ? $p['code'] : '',
        'title'         => isset($p['title']) ? $p['title'] : '',
        'category'      => isset($p['category']) ? $p['category'] : '',
        'categoryLabel' => !empty($p['categoryLabel']) ? $p['categoryLabel'] : (isset($p['category']) ? $p['category'] : ''),
        'location'      => isset($p['location']) ? $p['location'] : '',
        'src'           => $p['src'],
        'thumb'         => !empty($p['thumb']) ? $p['thumb'] : $p['src'],
        'w'             => isset($p['w']) ? (int) $p['w'] : 0,
        'h'             => isset($p['h']) ? (int) $p['h'] : 0,
        'camera'        => isset($p['camera']) ? $p['camera'] : '',
        'lens'          => isset($p['lens']) ? $p['lens'] : '',
        'aperture'      => isset($p['aperture']) ? $p['aperture'] : '',
        'shutter'       => isset($p['shutter']) ? $p['shutter'] : '',
        'iso'           => isset($p['iso']) ? $p['iso'] : '',
        'lighting'      => isset($p['lighting']) ? $p['lighting'] : '',
        'notes'         => isset($p['notes']) ? $p['notes'] : '',
        'featured'      => !empty($p['featured']),
    );
}

json_out(array(
    'ok'         => true,
    'photos'     => $public,
    'categories' => photos_categories($photos),
    'content'    => content_all(),
));
