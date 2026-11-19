<?php

// Analysis against an older Trust Levels in vendor: declare 1.1's TrustState so TrustRead's
// "new table" branch is checked too. A real TrustState in vendor always wins.
if (! class_exists(\ErnestDefoe\TrustLevels\TrustState::class)) {
    require __DIR__.'/TrustState.php';
}
