<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Vente extends Model
{
    /**
     * Le modèle Vente pointe vers une table dynamique : servmcljournal{Ymd}.
     * Par défaut, on utilise la table d'hier.
     * Utilisez setDateTable($date) pour changer de journée.
     */

    protected $table = 'servmcljournal'; // override via setDateTable()

    public $timestamps = false;

    protected $fillable = [
        'idquand',
        'idcint',
        'idlib',
        'E1',
        'idmttnet',
    ];

    // ── Table dynamique ──────────────────────────────────────────────────────

    /**
     * Crée une instance du modèle pointant vers la table du jour donné.
     *
     * Usage :
     *   $model = Vente::forDate('2025-03-10');
     *   $ventes = $model->newQuery()->paginate(50);
     */
    public static function forDate(string $date): self
    {
        $instance = new self();
        $instance->setTable('servmcljournal' . Carbon::parse($date)->format('Ymd'));
        return $instance;
    }

    /**
     * Nom de la table pour la date d'hier.
     */
    public static function yesterdayTable(): string
    {
        return 'servmcljournal' . Carbon::yesterday()->format('Ymd');
    }

    /**
     * Nom de la table pour une date donnée.
     */
    public static function tableForDate(string $date): string
    {
        return 'servmcljournal' . Carbon::parse($date)->format('Ymd');
    }
}
