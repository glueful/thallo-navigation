<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class AddNewTabToNavigationItems implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('navigation_items') || $schema->hasColumn('navigation_items', 'new_tab')) {
            return;
        }
        $schema->alterTable('navigation_items', function ($table): void {
            // "Open in a new window", per item: the rendered link carries target/rel. Off for
            // every row that exists, which is what they do today.
            $table->boolean('new_tab')->default(false);
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('navigation_items') && $schema->hasColumn('navigation_items', 'new_tab')) {
            $schema->alterTable('navigation_items', function ($table): void {
                $table->dropColumn('new_tab');
            });
        }
    }

    public function getDescription(): string
    {
        return 'Add navigation_items.new_tab (open the link in a new window).';
    }
}
