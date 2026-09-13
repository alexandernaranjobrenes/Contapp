<?php

namespace App\Domains\BusinessPartners\Models;

use App\Domains\Core\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BpFamily extends Model
{
    use BelongsToCompany, HasFactory;

    protected $fillable = ['company_id', 'code', 'name'];
}
