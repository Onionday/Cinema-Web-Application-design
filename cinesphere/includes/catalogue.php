<?php
/* Shared by the HTML page and modern search endpoint: one set of query rules. */
function catalogue_input(string $name, string $default = ''): string
{
    $value = $_GET[$name] ?? $default;
    return is_string($value) ? mb_substr(trim($value), 0, 60) : $default;
}
/* ---- Read and sanitise the filters -------------------------------- */
$q = catalogue_input('q', '');
$genre = catalogue_input('genre', '');
$rating = catalogue_input('rating', '');
$status = catalogue_input('status', 'now_showing');
$sort = catalogue_input('sort', 'score');

$validStatus = ['now_showing', 'coming_soon', 'all'];
if (!in_array($status, $validStatus, true)) {
    $status = 'now_showing';
}

$validRating = ['G', 'PG', 'PG13', 'NC16', 'M18'];
if ($rating !== '' && !in_array($rating, $validRating, true)) {
    $rating = '';
}

$sortMap = [
    'score'   => 'm.user_score DESC, m.title',
    'title'   => 'm.title',
    'newest'  => 'm.release_date DESC, m.title',
    'runtime' => 'm.duration_min, m.title',
];
if (!isset($sortMap[$sort])) {
    $sort = 'score';
}

/* ---- Build the query ---------------------------------------------- */
$where  = ["m.status <> 'archived'"];
$params = [];

if ($status !== 'all') {
    $where[]  = 'm.status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $where[]  = '(m.title LIKE ? OR m.synopsis LIKE ? OR m.cast_list LIKE ? OR m.director LIKE ?)';
    $like     = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($genre !== '') {
    $where[]  = 'm.genre LIKE ?';
    $params[] = '%' . $genre . '%';
}
if ($rating !== '') {
    $where[]  = 'm.classification = ?';
    $params[] = $rating;
}

$sql = 'SELECT m.*,
               (SELECT COUNT(*)
                  FROM showtimes st
                 WHERE st.movie_id = m.movie_id
                   AND st.status = ' . "'open'" . '
                   AND TIMESTAMP(st.show_date, st.show_time) >= NOW()) AS upcoming,
               (SELECT MIN(st.base_price)
                  FROM showtimes st
                 WHERE st.movie_id = m.movie_id
                   AND TIMESTAMP(st.show_date, st.show_time) >= NOW()) AS from_price
          FROM movies m
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY ' . $sortMap[$sort];

$films  = db_all($sql, $params);


/* Does the current filter set differ from the default? */
$filtered = ($q !== '' || $genre !== '' || $rating !== ''
             || $status !== 'now_showing' || $sort !== 'score');


$scope = $status === 'now_showing' ? 'on release right now'
       : ($status === 'coming_soon' ? 'opening in the next few months' : 'in the catalogue');
$summary = count($films) . (count($films) === 1 ? ' title ' : ' titles ') . $scope
         . '. Pick one to see its schedule and choose seats.';
