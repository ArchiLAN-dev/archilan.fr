<?php

declare(strict_types=1);

namespace App\CatalogSync\Application;

interface ImportedCatalogNamesQueryInterface
{
    /**
     * Returns catalog sheet names already imported into the library.
     *
     * @return list<string>
     */
    public function list(): array;
}
