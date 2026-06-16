<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Rapport Journalier - {{ $date }}</title>
    <style>
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            font-size: 12px;
            color: #333;
            line-height: 1.5;
            margin: 0;
            padding: 0;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #facc15;
            padding-bottom: 20px;
        }
        .header h1 {
            text-transform: uppercase;
            margin: 0;
            font-size: 24px;
            color: #000;
        }
        .header p {
            margin: 5px 0 0;
            color: #666;
            letter-spacing: 2px;
            font-weight: bold;
        }
        .report-info {
            margin-bottom: 20px;
            font-weight: bold;
        }
        .section-title {
            background-color: #f4f4f5;
            padding: 8px 12px;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 14px;
            border-left: 4px solid #facc15;
            margin-top: 30px;
            margin-bottom: 15px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th, td {
            text-align: left;
            padding: 10px;
            border-bottom: 1px solid #e4e4e7;
        }
        th {
            font-weight: bold;
            text-transform: uppercase;
            font-size: 10px;
            color: #71717a;
        }
        .amount {
            text-align: right;
            font-family: 'Courier', monospace;
            font-weight: bold;
        }
        .summary-box {
            margin-top: 40px;
            padding: 20px;
            background-color: #fafafa;
            border: 1px solid #e4e4e7;
            border-radius: 8px;
        }
        .summary-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 14px;
        }
        .summary-total {
            border-top: 2px solid #000;
            margin-top: 15px;
            padding-top: 15px;
            font-size: 18px;
            font-weight: black;
            color: #000;
        }
        .footer {
            position: fixed;
            bottom: 0;
            width: 100%;
            text-align: center;
            font-size: 8px;
            color: #999;
            padding-bottom: 10px;
        }
        .badge {
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .badge-income { background-color: #dcfce7; color: #166534; }
        .badge-expense { background-color: #fee2e2; color: #991b1b; }
    </style>
</head>
<body>
    <div class="header">
        <h1>LULUABOURG CRM</h1>
        @if(isset($isPeriod) && $isPeriod)
            <p>Hôtel & Terrasse - Rapport d'Audit Périodique</p>
        @else
            <p>Hôtel & Terrasse - Rapport Journalier d'Audit</p>
        @endif
    </div>

    <div class="report-info">
        @if(isset($isPeriod) && $isPeriod)
            Période du : {{ \Carbon\Carbon::parse($startDate)->translatedFormat('d F Y') }} au {{ \Carbon\Carbon::parse($endDate)->translatedFormat('d F Y') }}<br>
        @else
            Date du rapport : {{ \Carbon\Carbon::parse($startDate ?? $date)->translatedFormat('d F Y') }}<br>
        @endif
        Généré le : {{ now()->format('d/m/Y H:i') }} par {{ auth()->user()->name }}
    </div>

    <!-- Section Finances -->
    <div class="section-title">Flux de Trésorerie</div>
    <table>
        <thead>
            <tr>
                <th>Type</th>
                <th>Catégorie</th>
                <th>Description</th>
                <th class="amount">Montant (CDF)</th>
            </tr>
        </thead>
        <tbody>
            @forelse($transactions as $t)
                <tr>
                    <td>
                        <span class="badge {{ $t->type === 'entree' ? 'badge-income' : 'badge-expense' }}">
                            {{ $t->type === 'entree' ? 'Recette' : 'Dépense' }}
                        </span>
                    </td>
                    <td>{{ $t->category }}</td>
                    <td>{{ $t->description }}</td>
                    <td class="amount">
                        {{ $t->type === 'entree' ? '+' : '-' }} {{ number_format($t->amount, 0, ',', ' ') }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" style="text-align: center; padding: 20px; color: #999;">Aucune transaction enregistrée ce jour.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <!-- Section Hébergement -->
    <div class="section-title">État de l'Hébergement</div>
    <table>
        <thead>
            <tr>
                <th>Total Chambres</th>
                <th>Occupées</th>
                <th>En Nettoyage</th>
                <th>Taux d'Occupation</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $hotel_stats['total_rooms'] }}</td>
                <td>{{ $hotel_stats['occupied_rooms'] }}</td>
                <td>{{ $hotel_stats['dirty_rooms'] }}</td>
                <td><strong>{{ $hotel_stats['occupancy_rate'] }}%</strong></td>
            </tr>
        </tbody>
    </table>

    <!-- Section Stock Critique -->
    @if(count($low_stocks) > 0)
        <div class="section-title">Alertes Stocks (Critiques)</div>
        <table>
            <thead>
                <tr>
                    <th>Article</th>
                    <th>Catégorie</th>
                    <th>Stock Actuel</th>
                    <th>Seuil Alerte</th>
                </tr>
            </thead>
            <tbody>
                @foreach($low_stocks as $item)
                    <tr>
                        <td>{{ $item->name }}</td>
                        <td>{{ $item->category }}</td>
                        <td style="color: #991b1b; font-weight: bold;">{{ $item->stock }}</td>
                        <td>{{ $item->min_stock }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <!-- Bilan Final -->
    <div class="summary-box">
        <div class="summary-item" style="display: block;">
            <div style="float: left;">Total des Recettes :</div>
            <div style="float: right; color: #166534; font-weight: bold;">+ {{ number_format($totals['income'], 0, ',', ' ') }} CDF</div>
            <div style="clear: both;"></div>
        </div>
        <div class="summary-item" style="display: block; margin-top: 10px;">
            <div style="float: left;">Total des Dépenses :</div>
            <div style="float: right; color: #991b1b; font-weight: bold;">- {{ number_format($totals['expense'], 0, ',', ' ') }} CDF</div>
            <div style="clear: both;"></div>
        </div>
        <div class="summary-total">
            <div style="float: left;">BILAN NET DU JOUR :</div>
            <div style="float: right;">{{ number_format($totals['net'], 0, ',', ' ') }} CDF</div>
            <div style="clear: both;"></div>
        </div>
    </div>

    <div class="footer">
        LULUABOURG CRM - Système de Gestion Intégré v2.0 - Document confidentiel destiné à la direction.
    </div>
</body>
</html>
