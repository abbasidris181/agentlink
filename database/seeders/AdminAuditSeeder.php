<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class AdminAuditSeeder extends Seeder
{
    /**
     * Audit entries are recorded when real administrative actions occur; synthetic activity is never seeded.
     */
    public function run(): void {}
}
