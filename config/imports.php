<?php

return [
    'kickoff_mismatch_warning_minutes' => 15,

    // Ordered list: first data source slug in this array that has a
    // MatchStatistic row for a given match wins. Extend the array (do not
    // replace it) when a second statistics source is imported.
    'statistics_source_priority'       => ['football_data_co_uk'],
];
