<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateNavigationMenusTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('navigation_menus')) {
            return;
        }
        $schema->createTable('navigation_menus', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('slug', 64);
            $table->string('name', 120);
            // Optimistic concurrency for whole-tree PUTs (spec §5): stale version → 409.
            $table->integer('lock_version')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique('slug', 'uniq_navigation_menu_slug');
            $table->unique('uuid');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('navigation_menus');
    }

    public function getDescription(): string
    {
        return 'Create navigation_menus (menu identity + tree lock_version).';
    }
}
