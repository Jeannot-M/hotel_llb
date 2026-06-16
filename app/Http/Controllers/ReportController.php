<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Transaction;
use App\Models\Room;
use App\Models\Beverage;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;

class ReportController extends Controller
{
    public function daily(Request $request)
    {
        $startDate = $request->get('start_date', $request->get('date', Carbon::today()->toDateString()));
        $endDate = $request->get('end_date', $startDate);
        
        $isPeriod = $startDate !== $endDate;
        
        // 1. Get Transactions for the period
        $transactions = Transaction::whereBetween('date', [$startDate, $endDate])->get();
        
        $totals = [
            'income' => $transactions->where('type', 'entree')->sum('amount'),
            'expense' => $transactions->where('type', 'sortie')->sum('amount'),
        ];
        $totals['net'] = $totals['income'] - $totals['expense'];

        // 2. Get Hotel Stats (Current Live Stats)
        $rooms = Room::all();
        $hotel_stats = [
            'total_rooms' => $rooms->count(),
            'occupied_rooms' => $rooms->where('status', 'occupé')->count(),
            'dirty_rooms' => $rooms->where('status', 'nettoyage')->count(),
            'occupancy_rate' => $rooms->count() > 0 ? round(($rooms->where('status', 'occupé')->count() / $rooms->count()) * 100) : 0,
        ];

        // 3. Get Critical Stocks
        $low_stocks = Beverage::where('stock', '<=', 10)->get();

        $pdf = Pdf::loadView('reports.daily', [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'isPeriod' => $isPeriod,
            'date' => $startDate === $endDate ? $startDate : null,
            'transactions' => $transactions,
            'totals' => $totals,
            'hotel_stats' => $hotel_stats,
            'low_stocks' => $low_stocks,
        ]);

        $filename = $isPeriod ? "Rapport_Periode_{$startDate}_au_{$endDate}.pdf" : "Rapport_Journalier_{$startDate}.pdf";
        return $pdf->download($filename);
    }
}
