<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ParametrosDatasPagamentos extends Model
{

    protected $table = 'RH.PARAMETROS_DATAS_PAGAMENTOS';
    protected $primaryKey = 'PARAMETRO_DATA_PAGAMENTO';
    public $timestamps = false;



    public static function getValidacaoExecucao(int $mes, int $ano)
    {

        $query = self::where('MES', $mes)
            ->where('ANO', $ano)
            ->whereIn('TIPO_PAGAMENTO_ID', [1, 2])
            ->whereRaw('CAST(GETDATE() AS DATE) = DATEADD(DAY, -1, DATA_PAGAMENTO)')
            ->first();



        return $query ? true : false;
    }
}
