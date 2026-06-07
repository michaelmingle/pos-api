<?php
// app/Services/AccountingService.php

namespace App\Services;

use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\ChartOfAccount;
use App\Models\GlobalTransaction;
use App\Models\FinancialPeriod;
use App\Models\Shop;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;

class AccountingService
{
    /**
     * Initialize default chart of accounts for a shop
     */
    public function initializeChartOfAccounts($shopId)
    {
        $defaultAccounts = [
            // Assets
            ['code' => '1000', 'name' => 'Cash', 'type' => 'asset', 'sub_type' => 'current_asset'],
            ['code' => '1010', 'name' => 'Bank Account', 'type' => 'asset', 'sub_type' => 'current_asset'],
            ['code' => '1020', 'name' => 'Accounts Receivable', 'type' => 'asset', 'sub_type' => 'current_asset'],
            ['code' => '1030', 'name' => 'Inventory', 'type' => 'asset', 'sub_type' => 'current_asset'],
            ['code' => '1040', 'name' => 'Petty Cash', 'type' => 'asset', 'sub_type' => 'current_asset'],
            ['code' => '1500', 'name' => 'Fixed Assets', 'type' => 'asset', 'sub_type' => 'fixed_asset'],
            
            // Liabilities
            ['code' => '2000', 'name' => 'Accounts Payable', 'type' => 'liability', 'sub_type' => 'current_liability'],
            ['code' => '2010', 'name' => 'Tax Payable', 'type' => 'liability', 'sub_type' => 'current_liability'],
            ['code' => '2020', 'name' => 'Salary Payable', 'type' => 'liability', 'sub_type' => 'current_liability'],
            
            // Equity
            ['code' => '3000', 'name' => 'Owner\'s Equity', 'type' => 'equity', 'sub_type' => 'share_capital'],
            ['code' => '3010', 'name' => 'Retained Earnings', 'type' => 'equity', 'sub_type' => 'retained_earnings'],
            
            // Income
            ['code' => '4000', 'name' => 'Sales Revenue', 'type' => 'income', 'sub_type' => 'sales_revenue'],
            ['code' => '4010', 'name' => 'Service Revenue', 'type' => 'income', 'sub_type' => 'other_income'],
            ['code' => '4020', 'name' => 'Discounts Given', 'type' => 'income', 'sub_type' => 'other_income'],
            
            // Expenses
            ['code' => '5000', 'name' => 'Cost of Goods Sold', 'type' => 'expense', 'sub_type' => 'cost_of_goods_sold'],
            ['code' => '5010', 'name' => 'Rent Expense', 'type' => 'expense', 'sub_type' => 'operating_expense'],
            ['code' => '5020', 'name' => 'Utilities Expense', 'type' => 'expense', 'sub_type' => 'operating_expense'],
            ['code' => '5030', 'name' => 'Salary Expense', 'type' => 'expense', 'sub_type' => 'operating_expense'],
            ['code' => '5040', 'name' => 'Marketing Expense', 'type' => 'expense', 'sub_type' => 'operating_expense'],
            ['code' => '5050', 'name' => 'Depreciation', 'type' => 'expense', 'sub_type' => 'operating_expense'],
        ];
        
        foreach ($defaultAccounts as $account) {
            ChartOfAccount::create([
                'id' => (string) Str::uuid(),
                'shop_id' => $shopId,
                ...$account,
                'balance' => 0,
            ]);
        }
        
        // Create initial financial period
        $this->createFinancialPeriod($shopId);
    }
    
    /**
     * Create financial period
     */
    public function createFinancialPeriod($shopId)
    {
        $startDate = now()->startOfMonth();
        $endDate = now()->endOfMonth();
        
        FinancialPeriod::create([
            'id' => (string) Str::uuid(),
            'shop_id' => $shopId,
            'period_type' => 'month',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status' => 'open',
        ]);
    }
    
    /**
     * Create journal entry for sale
     */
    public function recordSale($invoice)
    {
        DB::beginTransaction();
        
        try {
            $entries = [
                [
                    'account_code' => '1010', // Bank/Cash
                    'debit' => $invoice->total,
                    'credit' => 0,
                ],
                [
                    'account_code' => '4000', // Sales Revenue
                    'debit' => 0,
                    'credit' => $invoice->total,
                ],
            ];
            
            // Add tax entry if applicable
            if ($invoice->tax > 0) {
                $entries[] = [
                    'account_code' => '2010', // Tax Payable
                    'debit' => 0,
                    'credit' => $invoice->tax,
                ];
            }
            
            $this->createJournalEntry(
                $invoice->shop_id,
                $entries,
                "Sale - Invoice {$invoice->invoice_number}",
                'sale',
                $invoice->id
            );
            
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
    
    /**
     * Create journal entry
     */
    public function createJournalEntry($shopId, $entries, $description, $referenceType, $referenceId)
    {
        // Get account IDs from codes
        $accountIds = [];
        foreach ($entries as &$entry) {
            $account = ChartOfAccount::where('shop_id', $shopId)
                ->where('code', $entry['account_code'])
                ->first();
                
            if (!$account) {
                throw new \Exception("Account not found: {$entry['account_code']}");
            }
            
            $entry['account_id'] = $account->id;
        }
        
        // Generate entry number
        $entryNumber = $this->generateEntryNumber($shopId);
        
        // Create journal entry
        $journalEntry = JournalEntry::create([
            'id' => (string) Str::uuid(),
            'shop_id' => $shopId,
            'entry_number' => $entryNumber,
            'entry_date' => now(),
            'description' => $description,
            'status' => 'posted',
            'created_by' => Auth::id(),
        ]);
        
        // Create journal entry lines
        foreach ($entries as $entry) {
            JournalEntryLine::create([
                'id' => (string) Str::uuid(),
                'journal_entry_id' => $journalEntry->id,
                'account_id' => $entry['account_id'],
                'debit' => $entry['debit'],
                'credit' => $entry['credit'],
                'description' => $entry['description'] ?? null,
                'reference_id' => $referenceId,
                'reference_type' => $referenceType,
            ]);
            
            // Update account balance
            $account = ChartOfAccount::find($entry['account_id']);
            $balanceChange = $entry['debit'] - $entry['credit'];
            
            // For liability, equity, income accounts, credit increases balance
            if (in_array($account->type, ['liability', 'equity', 'income'])) {
                $balanceChange = -$balanceChange;
            }
            
            $account->balance += $balanceChange;
            $account->save();
        }
        
        // Log global transaction
        GlobalTransaction::create([
            'id' => (string) Str::uuid(),
            'shop_id' => $shopId,
            'transaction_type' => $referenceType,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'amount' => collect($entries)->sum('debit'),
            'transaction_date' => now(),
            'details' => json_encode(['entry_number' => $entryNumber, 'description' => $description]),
        ]);
        
        return $journalEntry;
    }
    
    /**
     * Generate entry number
     */
    private function generateEntryNumber($shopId)
    {
        $lastEntry = JournalEntry::where('shop_id', $shopId)
            ->orderBy('created_at', 'desc')
            ->first();
            
        $year = date('Y');
        $month = date('m');
        
        if ($lastEntry && preg_match('/JE-' . $year . $month . '-(\d+)/', $lastEntry->entry_number, $matches)) {
            $number = intval($matches[1]) + 1;
        } else {
            $number = 1;
        }
        
        return 'JE-' . $year . $month . '-' . str_pad($number, 6, '0', STR_PAD_LEFT);
    }
    
    /**
     * Generate Balance Sheet
     */
    public function generateBalanceSheet($shopId, $asAtDate = null)
    {
        $date = $asAtDate ?? now();
        
        $accounts = ChartOfAccount::where('shop_id', $shopId)->get();
        
        $balanceSheet = [
            'assets' => [
                'current_assets' => 0,
                'fixed_assets' => 0,
                'total_assets' => 0,
            ],
            'liabilities' => [
                'current_liabilities' => 0,
                'total_liabilities' => 0,
            ],
            'equity' => [
                'total_equity' => 0,
            ],
        ];
        
        foreach ($accounts as $account) {
            $balance = $account->balance;
            
            switch ($account->type) {
                case 'asset':
                    if ($account->sub_type === 'current_asset') {
                        $balanceSheet['assets']['current_assets'] += $balance;
                    } else {
                        $balanceSheet['assets']['fixed_assets'] += $balance;
                    }
                    break;
                case 'liability':
                    $balanceSheet['liabilities']['current_liabilities'] += $balance;
                    break;
                case 'equity':
                    $balanceSheet['equity']['total_equity'] += $balance;
                    break;
            }
        }
        
        $balanceSheet['assets']['total_assets'] = 
            $balanceSheet['assets']['current_assets'] + 
            $balanceSheet['assets']['fixed_assets'];
            
        $balanceSheet['liabilities']['total_liabilities'] = 
            $balanceSheet['liabilities']['current_liabilities'];
            
        $balanceSheet['equity']['total_equity'] = 
            $balanceSheet['equity']['total_equity'];
            
        return $balanceSheet;
    }
    
    /**
     * Generate Profit & Loss Statement
     */
    public function generateProfitLoss($shopId, $startDate, $endDate)
    {
        $accounts = ChartOfAccount::where('shop_id', $shopId)->get();
        
        $profitLoss = [
            'income' => [
                'sales_revenue' => 0,
                'other_income' => 0,
                'total_income' => 0,
            ],
            'expenses' => [
                'cost_of_goods_sold' => 0,
                'operating_expenses' => 0,
                'total_expenses' => 0,
            ],
            'net_profit' => 0,
        ];
        
        foreach ($accounts as $account) {
            $balance = $account->balance;
            
            if ($account->type === 'income') {
                if ($account->sub_type === 'sales_revenue') {
                    $profitLoss['income']['sales_revenue'] += $balance;
                } else {
                    $profitLoss['income']['other_income'] += $balance;
                }
            } elseif ($account->type === 'expense') {
                if ($account->sub_type === 'cost_of_goods_sold') {
                    $profitLoss['expenses']['cost_of_goods_sold'] += $balance;
                } else {
                    $profitLoss['expenses']['operating_expenses'] += $balance;
                }
            }
        }
        
        $profitLoss['income']['total_income'] = 
            $profitLoss['income']['sales_revenue'] + 
            $profitLoss['income']['other_income'];
            
        $profitLoss['expenses']['total_expenses'] = 
            $profitLoss['expenses']['cost_of_goods_sold'] + 
            $profitLoss['expenses']['operating_expenses'];
            
        $profitLoss['net_profit'] = 
            $profitLoss['income']['total_income'] - 
            $profitLoss['expenses']['total_expenses'];
            
        return $profitLoss;
    }
}