<?php

namespace App\Http\Controllers\Backend\Pos;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderTransaction;
use App\Models\PosCart;
use App\Models\Product;
use Illuminate\Http\Request;
use Yajra\DataTables\DataTables;
use Illuminate\Support\Facades\Http; // <--- Add this line
use Carbon\Carbon; // <--- Add this line

class OrderController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        if ($request->ajax()) {
            $orders = Order::with('customer')->get();
            return DataTables::of($orders)
                ->addIndexColumn()
                ->addColumn('saleId', fn($data) => "#" . $data->id)
                ->addColumn('customer', fn($data) => $data->customer->name ?? '-')
                ->addColumn('item', fn($data) => $data->total_item)
                ->addColumn('sub_total', fn($data) => number_format($data->sub_total, 2, '.', ','))
                ->addColumn('discount', fn($data) => number_format($data->discount, 2, '.', ','))
                ->addColumn('total', fn($data) => number_format($data->total, 2, '.', ','))
                ->addColumn('paid', fn($data) => number_format($data->paid, 2, '.', ','))
                ->addColumn('due', fn($data) => number_format($data->due, 2, '.', ','))
                ->addColumn('status', fn($data) => $data->status
                    ? '<span class="badge bg-primary">Paid</span>'
                    : '<span class="badge bg-danger">Due</span>')
                ->addColumn('action', function ($data) {
                    $buttons = '';

                    $buttons .= '<a class="btn btn-success btn-sm" href="' . route('backend.admin.orders.invoice', $data->id) . '"><i class="fas fa-file-invoice"></i> Invoice</a>';

                    $buttons .= '<a class="btn btn-secondary btn-sm" href="' . route('backend.admin.orders.pos-invoice', $data->id) . '"><i class="fas fa-file-invoice"></i> Pos Invoice</a>';
                    if (!$data->status) {
                        $buttons .= '<a class="btn btn-warning btn-sm" href="' . route('backend.admin.due.collection', $data->id) . '"><i class="fas fa-receipt"></i> Due Collection</a>';
                    }
                    $buttons .= '<a class="btn btn-primary btn-sm" href="' . route('backend.admin.orders.transactions', $data->id) . '"><i class="fas fa-exchange-alt"></i> Transactions</a>';
                    return $buttons;
                })
                ->rawColumns(['saleId', 'customer', 'item', 'sub_total', 'discount', 'total', 'paid', 'due', 'status', 'action'])
                ->toJson();
        }
        return view('backend.orders.index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'customer_id' => [
                'required',
                'exists:customers,id',
                'integer', // Ensure customer_id is an integer
            ],
            'order_discount' => [
                'nullable',
                'numeric',
                'min:0',
            ],
            'paid' => [
                'nullable',
                'numeric',
                'min:0',
            ],
        ], [
            'customer_id.required' => 'Please select a customer.',
            'customer_id.exists' => 'The selected customer does not exist.',
            'order_discount.numeric' => 'The order discount must be a number.',
            'paid.numeric' => 'The amount paid must be a number.',
        ]);
        $carts = PosCart::with('product')->where('user_id', auth()->id())->get();
        $order = Order::create([
            'customer_id' => $request->customer_id,
            'user_id' => $request->user()->id,
        ]);
        $totalAmountOrder = 0;
        $orderDiscount = $request->order_discount;
        foreach ($carts as $cart) {
            $mainTotal = $cart->product->price * $cart->quantity;
            $totalAfterDiscount = $cart->product->discounted_price * $cart->quantity;
            $discount = $mainTotal - $totalAfterDiscount;
            $totalAmountOrder += $totalAfterDiscount;
            $order->products()->create([
                'quantity' => $cart->quantity,
                'price' => $cart->product->price,
                'purchase_price' => $cart->product->purchase_price,
                'sub_total' => $mainTotal,
                'discount' => $discount,
                'total' => $totalAfterDiscount,
                'product_id' => $cart->product->id,
            ]);
            $cart->product->quantity = $cart->product->quantity - $cart->quantity;
            $cart->product->save();
        }
        $total = $totalAmountOrder - $orderDiscount;
        $due = $total - $request->paid;
        $order->sub_total = $totalAmountOrder;
        $order->discount = $orderDiscount;
        $order->paid = $request->paid;
        $order->total = round((float) $total, 2);
        $order->due = round((float) $due, 2);
        $order->status = round((float) $due, 2) <= 0;
        $order->save();
        //create order transaction
        if ($request->paid > 0) {
            $orderTransaction = $order->transactions()->create([
                'amount' => $request->paid,
                'customer_id' => $order->customer_id,
                'user_id' => auth()->id(),
                'paid_by' => 'cash',
            ]);
        }

        // --- Start: Send to Make.com Integration ---
        // Ensure the order and its relationships are loaded before sending
        $order->load('customer', 'products.product', 'products.product.unit');
        $this->sendInvoiceToMake($order);
        // --- End: Send to Make.com Integration ---

        $carts = PosCart::where('user_id', auth()->id())->delete();
        return response()->json(['message' => 'Order completed successfully', 'order' => $order], 200);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        // This method doesn't currently finalize an order or payment.
        // If it ever does, you might consider calling $this->sendInvoiceToMake($order) here too.
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    public function invoice($id)
    {
        $order = Order::with(['customer', 'products.product'])->findOrFail($id);
        return view('backend.orders.print-invoice', compact('order'));
    }

    public function collection(Request $request, $id)
    {
        $order = Order::findOrFail($id);
        if ($request->isMethod('post')) {
            $data = $request->validate([
                'amount' => 'required|numeric|min:1',
            ]);

            $due = $order->due - $data['amount'];
            $paid = $order->paid + $data['amount'];
            $order->due = round((float) $due, 2);
            $order->paid = round((float) $paid, 2);
            $order->status = round((float) $due, 2) <= 0;
            $order->save();
            $collection_amount = $data['amount'];
            //create order transaction

            $orderTransaction = $order->transactions()->create([
                'amount' => $data['amount'],
                'customer_id' => $order->customer_id,
                'user_id' => auth()->id(),
                'paid_by' => 'cash',
            ]);

            // --- Optional: Send email for Due Collection Invoice ---
            // If you want to send a separate email for due collections,
            // you could create a similar sendCollectionInvoiceToMake method here.
            // For now, we're focusing on the initial order invoice.
            // --- End Optional ---

            return to_route('backend.admin.collectionInvoice', $orderTransaction->id);
        }
        return view('backend.orders.collection.create', compact('order'));
    }

    //collection invoice by order_transaction id
    public function collectionInvoice($id)
    {
        $transaction = OrderTransaction::findOrFail($id);
        $collection_amount = $transaction->amount;
        $order = $transaction->order;
        return view('backend.orders.collection.invoice', compact('order', 'collection_amount', 'transaction'));
    }

    //transactions by order id
    public function transactions($id)
    {
        $order = Order::with('transactions')->findOrFail($id);
        return view('backend.orders.collection.index', compact('order'));
    }

    public function posInvoice($id)
    {
        $order = Order::with(['customer', 'products.product'])->findOrFail($id);
        $maxWidth = readConfig('receiptMaxwidth') ?? '300px';
        return view('backend.orders.pos-invoice', compact('order', 'maxWidth'));
    }

    /**
     * Sends invoice data to Make.com webhook.
     * This is a private helper method.
     */
    private function sendInvoiceToMake(Order $order) {
        // Ensure you have your Make.com Webhook URL in your .env file
        $makeWebhookUrl = env('MAKE_WEBHOOK_URL');

        if (!$makeWebhookUrl) {
            \Log::error('MAKE_WEBHOOK_URL is not set in .env file. Invoice email not sent to Make.com.');
            return; // Exit if the URL isn't configured
        }

        // Prepare the data to send to Make.com as a JSON payload
        $invoiceData = [
            'order_id' => $order->id,
            'sale_date' => Carbon::parse($order->created_at)->format('d/m/Y'), // Uses original order date
            'current_date' => date('d/m/Y'), // Current date when sending
            'user_name' => auth()->user()->name ?? 'System User', // Fallback if no authenticated user
            'site_name' => readConfig('is_show_site_invoice') ? readConfig('site_name') : '',
            'site_logo_url' => readConfig('is_show_logo_invoice') ? assetImage(readConfig('site_logo')) : '',
            'contact_address' => readConfig('is_show_address_invoice') ? readConfig('contact_address') : '',
            'contact_phone' => readConfig('is_show_phone_invoice') ? readConfig('contact_phone') : '',
            'contact_email' => readConfig('is_show_email_invoice') ? readConfig('contact_email') : '',

            'customer_name' => readConfig('is_show_customer_invoice') ? ($order->customer->name ?? 'N/A') : '',
            'customer_address' => readConfig('is_show_customer_invoice') ? ($order->customer->address ?? 'N/A') : '',
            'customer_phone' => readConfig('is_show_customer_invoice') ? ($order->customer->phone ?? 'N/A') : '',
            'customer_email' => readConfig('is_show_customer_invoice') ? ($order->customer->email ?? '') : '', // Crucial for sending email!

            'sub_total' => number_format($order->sub_total, 2, '.', ''),
            'discount' => number_format($order->discount, 2, '.', ''),
            'total' => number_format($order->total, 2, '.', ''),
            'paid' => number_format($order->paid, 2, '.', ''),
            'due' => number_format($order->due, 2, '.', ''),
            'note_to_customer' => readConfig('is_show_note_invoice') ? readConfig('note_to_customer_invoice') : '',

            'products' => $order->products->map(function ($item) {
                return [
                    'sn' => $item->id, // This is okay, or you could make it a sequential index if needed
                    'product_name' => $item->product->name,
                    'quantity' => $item->quantity,
                    'unit_short_name' => optional($item->product->unit)->short_name,
                    'original_price' => number_format($item->price, 2, '.', ''),
                    'discounted_price' => number_format($item->discounted_price, 2, '.', ''),
                    'total_item_price' => number_format($item->total, 2, '.', ''),
                    'has_discount_on_item' => ($item->price > $item->discounted_price),
                ];
            })->toArray(),
        ];

        try {
            $response = Http::post($makeWebhookUrl, $invoiceData);

            if ($response->successful()) {
                \Log::info('Invoice data sent to Make.com successfully for Order ID: ' . $order->id);
            } else {
                \Log::error('Failed to send invoice data to Make.com for Order ID: ' . $order->id, [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Exception $e) {
            \Log::error('Error sending invoice data to Make.com: ' . $e->getMessage(), ['order_id' => $order->id]);
        }
    }
}
