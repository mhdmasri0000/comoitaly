<?php



namespace App\Http\Controllers\Api;



use App\Http\Controllers\Controller;

use App\Models\Category;

use App\Models\Order;

use App\Models\OrderItem;

use App\Models\Product;

use App\Models\User;

use App\Support\ApiResponse;

use App\Support\ProductSerializer;

use Carbon\CarbonPeriod;

use Illuminate\Http\Request;

use Illuminate\Support\Facades\DB;



class AnalyticsController extends Controller

{

    public function stats()

    {

        $paidOrders = Order::query()->where('status', '!=', 'cancelled');

        $totalOrders = Order::count();

        $totalRevenue = (float) (clone $paidOrders)->sum('total');

        $avgOrderValue = $totalOrders > 0

            ? round($totalRevenue / max((clone $paidOrders)->count(), 1), 2)

            : 0.0;



        $statusCounts = Order::query()

            ->select('status', DB::raw('COUNT(*) as total'))

            ->groupBy('status')

            ->pluck('total', 'status');



        $todayRevenue = (float) Order::query()

            ->where('status', '!=', 'cancelled')

            ->whereDate('created_at', today())

            ->sum('total');

        $todayOrders = Order::query()->whereDate('created_at', today())->count();



        $currentFrom = now()->subDays(6)->startOfDay();

        $previousFrom = now()->subDays(13)->startOfDay();

        $previousTo = now()->subDays(7)->endOfDay();



        $currentRevenue = (float) Order::query()

            ->where('status', '!=', 'cancelled')

            ->where('created_at', '>=', $currentFrom)

            ->sum('total');

        $previousRevenue = (float) Order::query()

            ->where('status', '!=', 'cancelled')

            ->whereBetween('created_at', [$previousFrom, $previousTo])

            ->sum('total');



        $currentOrders = Order::query()->where('created_at', '>=', $currentFrom)->count();

        $previousOrders = Order::query()->whereBetween('created_at', [$previousFrom, $previousTo])->count();



        $currentUsers = User::query()->where('created_at', '>=', $currentFrom)->count();

        $previousUsers = User::query()->whereBetween('created_at', [$previousFrom, $previousTo])->count();



        return ApiResponse::success('Analytics stats retrieved successfully', [

            'users' => User::count(),

            'orders' => $totalOrders,

            'revenue' => $totalRevenue,

            'totalUsers' => User::count(),

            'totalOrders' => $totalOrders,

            'totalRevenue' => $totalRevenue,

            'totalProducts' => Product::count(),

            'totalCategories' => Category::count(),

            'avgOrderValue' => $avgOrderValue,

            'todayRevenue' => $todayRevenue,

            'todayOrders' => $todayOrders,

            'pendingOrders' => (int) ($statusCounts['pending'] ?? 0),

            'processingOrders' => (int) ($statusCounts['processing'] ?? 0),

            'completedOrders' => (int) (($statusCounts['completed'] ?? 0) + ($statusCounts['delivered'] ?? 0)),

            'cancelledOrders' => (int) ($statusCounts['cancelled'] ?? 0),

            'lowStockProducts' => Product::query()->where('quantity', '<=', 5)->count(),

            'outOfStockProducts' => Product::query()->where('quantity', '<=', 0)->count(),

            'featuredProducts' => Product::query()->where('is_featured', true)->count(),

            'offerProducts' => Product::query()->where('is_offer', true)->count(),

            'ordersByStatus' => [

                'pending' => (int) ($statusCounts['pending'] ?? 0),

                'processing' => (int) ($statusCounts['processing'] ?? 0),

                'shipped' => (int) ($statusCounts['shipped'] ?? 0),

                'completed' => (int) (($statusCounts['completed'] ?? 0) + ($statusCounts['delivered'] ?? 0)),

                'cancelled' => (int) ($statusCounts['cancelled'] ?? 0),

            ],

            'trends' => [

                'revenue' => $this->percentChange($currentRevenue, $previousRevenue),

                'orders' => $this->percentChange($currentOrders, $previousOrders),

                'users' => $this->percentChange($currentUsers, $previousUsers),

            ],

        ]);

    }



    public function categorySales()

    {

        $rows = DB::table('order_items')

            ->join('products', 'products.id', '=', 'order_items.product_id')

            ->join('categories', 'categories.id', '=', 'products.category_id')

            ->select(

                'categories.id',

                'categories.name',

                DB::raw('SUM(order_items.quantity) as sold_quantity'),

                DB::raw('SUM(order_items.quantity * order_items.unit_price) as revenue')

            )

            ->groupBy('categories.id', 'categories.name')

            ->orderByDesc('revenue')

            ->get()

            ->map(function ($row) {

                $name = $row->name;

                if (is_string($name)) {

                    $decoded = json_decode($name, true);

                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {

                        $name = $decoded;

                    }

                }



                $localized = ProductSerializer::normalizeLocalized($name);



                return [

                    'id' => $row->id,

                    'name' => $localized,

                    'category' => $localized['en'] !== '' ? $localized['en'] : $localized['ar'],

                    'sold_quantity' => (int) $row->sold_quantity,

                    'revenue' => (float) $row->revenue,

                    'value' => (float) $row->revenue,

                ];

            })

            ->values();



        return ApiResponse::success('Category sales retrieved successfully', $rows);

    }



    public function topProducts(Request $request)

    {

        $limit = $request->validate(['limit' => ['sometimes', 'integer', 'min:1', 'max:100']])['limit'] ?? 5;



        return ApiResponse::success('Top products retrieved successfully', OrderItem::select('product_id', DB::raw('SUM(quantity) as sold_quantity'))

            ->groupBy('product_id')

            ->orderByDesc('sold_quantity')

            ->limit($limit)

            ->with('product.images')

            ->get());

    }



    public function daily(Request $request, string $metric)

    {

        $data = $request->validate([

            'from' => ['nullable', 'date'],

            'to' => ['nullable', 'date', 'after_or_equal:from'],

        ]);



        $from = $data['from'] ?? now()->subDays(29)->toDateString();

        $to = $data['to'] ?? now()->toDateString();



        $query = $this->buildDailyQuery($metric);

        $query->whereDate('created_at', '>=', $from)

            ->whereDate('created_at', '<=', $to);



        $values = $query->get()->keyBy('date');



        $series = [];

        foreach (CarbonPeriod::create($from, '1 day', $to) as $date) {

            $dateKey = $date->toDateString();

            $series[] = [

                'date' => $dateKey,

                'count' => (float) ($values[$dateKey]->count ?? 0),

            ];

        }



        return ApiResponse::success(ucfirst($metric).' daily data retrieved successfully', $series);

    }



    protected function buildDailyQuery(string $metric)

    {

        $model = $metric === 'users' ? User::query() : Order::query();



        if ($metric === 'revenue') {

            return $model->selectRaw('DATE(created_at) as date, COALESCE(SUM(total), 0) as count')

                ->where('status', '!=', 'cancelled')

                ->groupBy('date')

                ->orderBy('date');

        }



        return $model->selectRaw('DATE(created_at) as date, COUNT(*) as count')

            ->groupBy('date')

            ->orderBy('date');

    }



    protected function percentChange(float|int $current, float|int $previous): float

    {

        if ((float) $previous === 0.0) {

            return $current > 0 ? 100.0 : 0.0;

        }



        return round((((float) $current - (float) $previous) / (float) $previous) * 100, 1);

    }

}

