<?php

namespace App\Http\Controllers;

use App\Models\MaterialOrder;
use App\Models\MaterialOrderItem;
use App\Models\RawMaterial;
use App\Models\Outlet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class MaterialOrderController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = MaterialOrder::with(['franchise', 'user']);

        // Filter by outlet based on role
        if (Auth::user()->role !== 'owner') {
            $query->where('franchise_id', Auth::user()->outlet_id);
        } elseif ($request->franchise_id) {
            $query->where('franchise_id', $request->franchise_id);
        }

        // Filter by status
        if ($request->has('status') && $request->status != '') {
            $query->where('status', $request->status);
        }

        // Filter by payment method
        if ($request->has('payment_method') && $request->payment_method != '') {
            $query->where('payment_method', $request->payment_method);
        }

        // Handle date filtering with multiple options
        // 1. Date Range Button (daterange-btn)
        if ($request->filled('date_start') && $request->filled('date_end')) {
            $startDate = Carbon::parse($request->date_start)->startOfDay();
            $endDate = Carbon::parse($request->date_end)->endOfDay();
            $query->whereBetween('created_at', [$startDate, $endDate]);
        }

        // 2. Single Date Picker (datepicker)
        elseif ($request->filled('single_date')) {
            $date = Carbon::parse($request->single_date);
            $query->whereDate('created_at', $date);
        }

        // 3. Date Range Picker (daterange-cus)
        elseif ($request->filled('date_range')) {
            try {
                $dates = explode(' - ', $request->date_range);
                if (count($dates) == 2) {
                    $startDate = Carbon::parse($dates[0])->startOfDay();
                    $endDate = Carbon::parse($dates[1])->endOfDay();
                    $query->whereBetween('created_at', [$startDate, $endDate]);
                }
            } catch (\Exception $e) {
                \Log::error('Failed to parse date range: ' . $e->getMessage());
            }
        }

        // Sort by creation date (newest first by default)
        $query->latest();

        $materialOrders = $query->paginate(10)->withQueryString();
        $outlets = Outlet::all();

        return view('pages.material-orders.index', compact('materialOrders', 'outlets'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $rawMaterials = RawMaterial::where('is_active', true)->get();

        // For owner, show all outlets; for others, just their own outlet
        if (Auth::user()->role === 'owner') {
            $outlets = Outlet::all();
        } else {
            $outlets = Outlet::where('id', Auth::user()->outlet_id)->get();
        }

        // Payment method options
        $paymentMethods = [
            'cash' => 'Tunai',
            'bank_transfer' => 'Bank Transfer',
            'e-wallet' => 'E-Wallet'
        ];

        return view('pages.material-orders.create', compact('rawMaterials', 'outlets', 'paymentMethods'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'franchise_id' => 'required|exists:outlets,id',
            'payment_method' => 'required|in:cash,bank_transfer,e-wallet',
            'notes' => 'nullable|string',
            'materials' => 'required|array|min:1',
            'materials.*.raw_material_id' => 'required|exists:raw_materials,id',
            'materials.*.quantity' => 'required|integer|min:1',
        ]);

        DB::beginTransaction();
        try {
            $totalAmount = 0;

            // Calculate total amount and validate each item
            foreach ($request->materials as $item) {
                $rawMaterial = RawMaterial::findOrFail($item['raw_material_id']);
                $subtotal = $rawMaterial->price * $item['quantity'];
                $totalAmount += $subtotal;
            }

            // Create material order
            $materialOrder = MaterialOrder::create([
                'franchise_id' => $request->franchise_id,
                'user_id' => Auth::id(),
                'status' => 'pending',
                'payment_method' => $request->payment_method,
                'total_amount' => $totalAmount,
                'notes' => $request->notes,
            ]);

            // Create order items
            foreach ($request->materials as $item) {
                $rawMaterial = RawMaterial::findOrFail($item['raw_material_id']);
                $subtotal = $rawMaterial->price * $item['quantity'];

                MaterialOrderItem::create([
                    'material_order_id' => $materialOrder->id,
                    'raw_material_id' => $item['raw_material_id'],
                    'quantity' => $item['quantity'],
                    'price_per_unit' => $rawMaterial->price,
                    'subtotal' => $subtotal,
                ]);
            }

            DB::commit();
            return redirect()->route('material-orders.index')
                ->with('success', 'Material order created successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()
                ->with('error', 'Failed to create material order: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(MaterialOrder $materialOrder)
    {
        // Authorize that the user can view this order
        if (Auth::user()->role !== 'owner' && $materialOrder->franchise_id !== Auth::user()->outlet_id) {
            return redirect()->route('material-orders.index')
                ->with('error', 'You do not have permission to view this order');
        }

        $materialOrder->load(['franchise', 'user', 'items.rawMaterial']);

        return view('pages.material-orders.show', compact('materialOrder'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(MaterialOrder $materialOrder)
    {
        // Authorize that the user can edit this order
        if (Auth::user()->role !== 'owner' && $materialOrder->franchise_id !== Auth::user()->outlet_id) {
            return redirect()->route('material-orders.index')
                ->with('error', 'You do not have permission to edit this order');
        }

        // Only pending orders can be edited
        if ($materialOrder->status !== 'pending') {
            return redirect()->route('material-orders.show', $materialOrder)
                ->with('error', 'Only pending orders can be edited');
        }

        $rawMaterials = RawMaterial::where('is_active', true)->get();

        // For owner, show all outlets; for others, just their own outlet
        if (Auth::user()->role === 'owner') {
            $outlets = Outlet::all();
        } else {
            $outlets = Outlet::where('id', Auth::user()->outlet_id)->get();
        }

        // Payment method options
        $paymentMethods = [
            'cash' => 'Tunai',
            'bank_transfer' => 'Bank Transfer',
            'e-wallet' => 'E-Wallet'
        ];

        $materialOrder->load(['items.rawMaterial', 'franchise', 'user']);

        return view('pages.material-orders.edit', compact('materialOrder', 'rawMaterials', 'outlets', 'paymentMethods'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, MaterialOrder $materialOrder)
    {
        // Authorize that the user can update this order
        if (Auth::user()->role !== 'owner' && $materialOrder->franchise_id !== Auth::user()->outlet_id) {
            return redirect()->route('material-orders.index')
                ->with('error', 'You do not have permission to update this order');
        }

        // Only pending orders can be updated
        if ($materialOrder->status !== 'pending') {
            return redirect()->route('material-orders.show', $materialOrder)
                ->with('error', 'Only pending orders can be updated');
        }

        $request->validate([
            'franchise_id' => 'required|exists:outlets,id',
            'payment_method' => 'required|in:cash,bank_transfer,e-wallet',
            'notes' => 'nullable|string',
            'materials' => 'required|array|min:1',
            'materials.*.raw_material_id' => 'required|exists:raw_materials,id',
            'materials.*.quantity' => 'required|integer|min:1',
        ]);

        DB::beginTransaction();
        try {
            $totalAmount = 0;

            // Calculate total amount and validate each item
            foreach ($request->materials as $item) {
                $rawMaterial = RawMaterial::findOrFail($item['raw_material_id']);
                $subtotal = $rawMaterial->price * $item['quantity'];
                $totalAmount += $subtotal;
            }

            // Update material order
            $materialOrder->update([
                'franchise_id' => $request->franchise_id,
                'payment_method' => $request->payment_method,
                'total_amount' => $totalAmount,
                'notes' => $request->notes,
            ]);

            // Delete existing order items
            $materialOrder->items()->delete();

            // Create new order items
            foreach ($request->materials as $item) {
                $rawMaterial = RawMaterial::findOrFail($item['raw_material_id']);
                $subtotal = $rawMaterial->price * $item['quantity'];

                MaterialOrderItem::create([
                    'material_order_id' => $materialOrder->id,
                    'raw_material_id' => $item['raw_material_id'],
                    'quantity' => $item['quantity'],
                    'price_per_unit' => $rawMaterial->price,
                    'subtotal' => $subtotal,
                ]);
            }

            DB::commit();
            return redirect()->route('material-orders.show', $materialOrder)
                ->with('success', 'Material order updated successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()
                ->with('error', 'Failed to update material order: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Update the status of material order
     */
    public function updateStatus(Request $request, MaterialOrder $materialOrder)
    {
        $request->validate([
            'status' => 'required|in:approved,delivered',
        ]);

        // Only owner can approve/deliver orders
        if (Auth::user()->role !== 'owner') {
            return redirect()->back()
                ->with('error', 'You do not have permission to perform this action');
        }

        DB::beginTransaction();
        try {
            $updateData = [
                'status' => $request->status,
            ];

            // Set timestamp for status changes
            if ($request->status === 'approved') {
                $updateData['approved_at'] = now();
            } else if ($request->status === 'delivered') {
                $updateData['delivered_at'] = now();

                // Update stock quantities when order is delivered
                foreach ($materialOrder->items as $item) {
                    $rawMaterial = $item->rawMaterial;
                    // Kurangi stok
                    $rawMaterial->stock -= $item->quantity;

                    // Validasi stok tidak boleh negatif
                    if ($rawMaterial->stock < 0) {
                        DB::rollBack();
                        return redirect()->back()
                            ->with('error', 'Insufficient stock for ' . $rawMaterial->name);
                    }

                    $rawMaterial->save();
                }
            }

            $materialOrder->update($updateData);

            DB::commit();
            return redirect()->route('material-orders.show', $materialOrder)
                ->with('success', 'Order status updated successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()
                ->with('error', 'Failed to update order status: ' . $e->getMessage());
        }
    }

    /**
     * Cancel a pending material order
     */
    public function cancel(MaterialOrder $materialOrder)
    {
        // Only pending orders can be cancelled
        if ($materialOrder->status !== 'pending') {
            return redirect()->back()
                ->with('error', 'Only pending orders can be cancelled');
        }

        // Can only cancel own orders if not owner
        if (Auth::user()->role !== 'owner' && $materialOrder->user_id !== Auth::id()) {
            return redirect()->back()
                ->with('error', 'You can only cancel your own orders');
        }

        try {
            $materialOrder->delete();
            return redirect()->route('material-orders.index')
                ->with('success', 'Material order cancelled successfully');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Failed to cancel order: ' . $e->getMessage());
        }
    }

    /**
     * Soft delete all raw materials
     */
    public function deleteAll()
    {
        try {
            DB::beginTransaction();

            // Log start of operation
            \Log::info('Memulai proses deleteAll untuk bahan baku');

            // Check if any materials are being used in orders
            $materialsInUse = RawMaterial::whereHas('materialOrderItems')->get();

            if ($materialsInUse->isNotEmpty()) {
                // Prepare detailed information about materials in use
                $materialInfo = $materialsInUse->map(function ($material) {
                    return "{$material->name} (ID: {$material->id})";
                })->join(', ');

                // Roll back and return message
                DB::rollBack();
                return redirect()->route('raw-materials.index')
                    ->with('warning', "Tidak dapat menghapus semua bahan baku. Bahan baku berikut masih digunakan dalam pesanan: {$materialInfo}");
            }

            // Track how many materials will be deleted
            $materialCount = RawMaterial::count();

            if ($materialCount === 0) {
                DB::rollBack();
                return redirect()->route('raw-materials.index')
                    ->with('info', 'Tidak ada bahan baku yang ditemukan untuk dihapus.');
            }

            // Soft delete all materials (the SoftDeletes trait will make this a soft delete)
            RawMaterial::query()->delete();

            // Commit the transaction
            DB::commit();

            // Log successful deletion
            \Log::info("Berhasil soft delete semua {$materialCount} bahan baku");

            return redirect()->route('raw-materials.index')
                ->with('success', "Semua {$materialCount} bahan baku berhasil dihapus.");
        } catch (\Exception $e) {
            // Log error
            \Log::error('Error dalam deleteAll bahan baku: ' . $e->getMessage());

            // Rollback transaction if still active
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return redirect()->route('raw-materials.index')
                ->with('error', 'Kesalahan menghapus bahan baku: ' . $e->getMessage());
        }
    }
}
