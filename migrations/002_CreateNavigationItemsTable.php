<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateNavigationItemsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('navigation_items')) {
            return;
        }
        $schema->createTable('navigation_items', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('menu_uuid', 12);
            $table->string('parent_uuid', 12)->nullable();
            $table->integer('position')->default(0);
            // entry (soft reference — no cross-package FK) | url
            $table->string('kind', 8);
            $table->string('entry_uuid', 12)->nullable();
            $table->string('url', 1024)->nullable();
            // Optional Lucide icon name rendered before the label (nav-v2 spec §5).
            $table->string('icon', 64)->nullable();
            // locale → label; resolution falls back requested → default locale → any.
            $table->json('labels');
            // Optional locale → description (nav-v2 megamenu): a short supporting line
            // rendered under the label in dropdown/megamenu panels. Same locale
            // fallback as labels; null/absent = no description.
            $table->json('descriptions')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['menu_uuid', 'parent_uuid', 'position'], 'idx_navigation_items_tree');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('navigation_items');
    }

    public function getDescription(): string
    {
        return 'Create navigation_items (menu tree nodes with per-locale labels).';
    }
}
