<?php

namespace Tests\Unit;

use App\Constants\Currency;
use App\Constants\PaymentStatus;
use App\Exceptions\AlreadyRepaidException;
use App\Exceptions\AmountHigherThanOutstandingAmountException;
use App\Models\Loan;
use App\Models\ReceivedRepayment;
use App\Models\ScheduledRepayment;
use App\Models\User;
use App\Services\LoanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LoanServiceTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;

    protected User $customer;
    protected LoanService $loanService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::factory()->create();
        $this->loanService = new LoanService();
    }

    /**
     * @test
     * @dataProvider createLoanDataProvider
     */
    public function can_create_loan_for_a_customer($terms, $amount, $currencyCode, $processedAt, $scheduledRepaymentAmounts): void
    {
        // 2️⃣ Act 🏋🏻‍
        $loan = $this->loanService->createLoan($this->customer, $amount, $currencyCode, $terms, $processedAt);

        // 3️⃣ Assert ✅
        $this->assertDatabaseHas(Loan::class, [
            'id'                 => $loan->id,
            'user_id'            => $this->customer->id,
            'amount'             => $amount,
            'terms'              => $terms,
            'outstanding_amount' => $amount,
            'currency_code'      => $currencyCode,
            'processed_at'       => $processedAt,
            'status'             => PaymentStatus::DUE,
        ]);

        $this->assertCount($terms, $loan->scheduledRepayments);

        foreach ($loan->scheduledRepayments as $index => $scheduledRepayment) {
            $this->assertDatabaseHas(ScheduledRepayment::class, [
                'loan_id'            => $loan->id,
                'amount'             => $scheduledRepaymentAmounts[$index],
                'outstanding_amount' => $scheduledRepaymentAmounts[$index],
                'currency_code'      => $currencyCode,
                'due_date'           => $processedAt->clone()->addMonth($index + 1),
                'status'             => PaymentStatus::DUE,
            ]);
        }

        $this->assertEquals($amount, $loan->scheduledRepayments()->sum('amount'));
    }

    /** @test */
    public function can_pay_a_scheduled_payment(): void
    {
        // 1️⃣ Arrange 🏗
        $loan = $this->loanService->createLoan(
            $this->customer,
            5000,
            Currency::TRY,
            3,
            Carbon::parse('2022-01-20'),
        );

        $receivedRepayment = 1666;
        $currencyCode = Currency::TRY;
        $receivedAt = Carbon::parse('2022-02-20');

        // 2️⃣ Act 🏋🏻‍
        $loan = $this->loanService->repayLoan($loan, $receivedRepayment, $currencyCode, $receivedAt);

        // 3️⃣ Assert ✅
        // Assert loan values
        $this->assertDatabaseHas(Loan::class, [
            'id'                 => $loan->id,
            'user_id'            => $this->customer->id,
            'amount'             => 5000,
            'outstanding_amount' => 5000 - 1666,
            'currency_code'      => $currencyCode,
            'status'             => PaymentStatus::DUE,
            'processed_at'       => Carbon::parse('2022-01-20'),
        ]);

        // Asserting First Scheduled Repayment is Repaid
        $this->assertDatabaseHas(ScheduledRepayment::class, [
            'loan_id'            => $loan->id,
            'amount'             => 1666,
            'outstanding_amount' => 0,
            'currency_code'      => $currencyCode,
            'due_date'           => Carbon::parse('2022-02-20'),
            'status'             => PaymentStatus::REPAID,
        ]);

        // Asserting Second and Third Scheduled Repayments are still due
        $this->assertDatabaseHas(ScheduledRepayment::class, [
            'status'   => PaymentStatus::DUE,
            'due_date' => Carbon::parse('2022-03-20'),
        ]);

        $this->assertDatabaseHas(ScheduledRepayment::class, [
            'status'   => PaymentStatus::DUE,
            'due_date' => Carbon::parse('2022-04-20'),
        ]);

        // Asserting Received Repayment
        $this->assertDatabaseHas(ReceivedRepayment::class, [
            'loan_id'       => $loan->id,
            'amount'        => 1666,
            'currency_code' => $currencyCode,
            'received_at'   => $receivedAt,
        ]);
    }

    /** @test */
    public function can_pay_a_scheduled_payment_consecutively(): void
    {
        // 1️⃣ Arrange 🏗
        $loan = $this->loanService->createLoan(
            $this->customer,
            5000,
            Currency::TRY,
            3,
            Carbon::parse('2022-01-20'),
        );

        // First two scheduled repayments are already repaid and the last one is due
        $loan->update(['outstanding_amount' => 5000 - (1666 * 2)]);

        foreach ($loan->scheduledRepayments->take(2) as $scheduledRepayment) {
            $scheduledRepayment->update([
                'status'             => PaymentStatus::REPAID,
                'outstanding_amount' => 0,
            ]);
        }

        $receivedRepayment = 1668;
        $currencyCode = Currency::TRY;
        $receivedAt = Carbon::parse('2022-04-20');

        // 2️⃣ Act 🏋🏻‍
        // Repaying the last one
        $loan = $this->loanService->repayLoan($loan, $receivedRepayment, $currencyCode, $receivedAt);

        // 3️⃣ Assert ✅
        // Asserting Loan values
        $this->assertDatabaseHas(Loan::class, [
            'id'                 => $loan->id,
            'user_id'            => $this->customer->id,
            'amount'             => 5000,
            'outstanding_amount' => 0,
            'currency_code'      => $currencyCode,
            'status'             => PaymentStatus::REPAID,
            'processed_at'       => Carbon::parse('2022-01-20'),
        ]);

        // Asserting Last Scheduled Repayment is Repaid
        $this->assertDatabaseHas(ScheduledRepayment::class, [
            'loan_id'            => $loan->id,
            'amount'             => 1668,
            'outstanding_amount' => 0,
            'currency_code'      => $currencyCode,
            'due_date'           => Carbon::parse('2022-04-20'),
            'status'             => PaymentStatus::REPAID,
        ]);

        // Asserting Received Repayment
        $this->assertDatabaseHas(ReceivedRepayment::class, [
            'loan_id'       => $loan->id,
            'amount'        => 1668,
            'currency_code' => $currencyCode,
            'received_at'   => Carbon::parse('2022-04-20'),
        ]);
    }

    /** @test */
    public function can_pay_multiple_scheduled_payment(): void
    {
        // 1️⃣ Arrange 🏗
        $loan = $this->loanService->createLoan(
            $this->customer,
            5000,
            Currency::TRY,
            3,
            Carbon::parse('2022-01-20'),
        );

        // Paying more than the first scheduled repayment amount
        $receivedRepayment = 2000;
        $currencyCode = Currency::TRY;
        $receivedAt = Carbon::parse('2022-02-20');

        // 2️⃣ Act 🏋🏻‍
        $loan = $this->loanService->repayLoan($loan, $receivedRepayment, $currencyCode, $receivedAt);

        // 3️⃣ Assert ✅
        // Asserting Loan values
        $this->assertDatabaseHas(Loan::class, [
            'id'                 => $loan->id,
            'user_id'            => $this->customer->id,
            'amount'             => 5000,
            'outstanding_amount' => 5000 - 2000,
            'currency_code'      => $currencyCode,
            'status'             => PaymentStatus::DUE,
            'processed_at'       => Carbon::parse('2022-01-20'),
        ]);

        // Asserting First Scheduled Repayment is Repaid
        $this->assertDatabaseHas(ScheduledRepayment::class, [
            'loan_id'            => $loan->id,
            'amount'             => 1666,
            'outstanding_amount' => 0,
            'currency_code'      => $currencyCode,
            'due_date'           => Carbon::parse('2022-02-20'),
            'status'             => PaymentStatus::REPAID,
        ]);

        // Asserting Second Scheduled Repayment is Partial
        $this->assertDatabaseHas(ScheduledRepayment::class, [
            'loan_id'            => $loan->id,
            'amount'             => 1666,
            'outstanding_amount' => 1332, // 1666 - (2000 - 1666)
            'currency_code'      => $currencyCode,
            'due_date'           => Carbon::parse('2022-03-20'),
            'status'             => PaymentStatus::PARTIAL,
        ]);

        // Asserting Received Repayment
        $this->assertDatabaseHas(ReceivedRepayment::class, [
            'loan_id'       => $loan->id,
            'amount'        => 2000,
            'currency_code' => $currencyCode,
            'received_at'   => Carbon::parse('2022-02-20'),
        ]);
    }

    /** @test */
    public function can_not_pay_more_than_outstanding_amount(): void
    {
        // 1️⃣ Arrange 🏗
        $loan = $this->loanService->createLoan(
            $this->customer,
            5000,
            Currency::TRY,
            3,
            Carbon::parse('2022-01-20'),
        );

        // 3️⃣ Assert ✅
        $this->expectException(AmountHigherThanOutstandingAmountException::class);

        // 2️⃣ Act 🏋🏻‍
        $this->loanService->repayLoan($loan, 5001, Currency::TRY, Carbon::now());
    }

    /** @test */
    public function can_not_pay_a_loan_if_already_repaid(): void
    {
        // 1️⃣ Arrange 🏗
        $loan = Loan::factory()->create(['status' => PaymentStatus::REPAID]);

        // 3️⃣ Assert ✅
        $this->expectException(AlreadyRepaidException::class);

        // 2️⃣ Act 🏋🏻‍
        $this->loanService->repayLoan($loan, 5001, Currency::TRY, Carbon::now());
    }

    public function createLoanDataProvider(): array
    {
        return [
            'Case #1' => [3, 5000, Currency::TRY, Carbon::now()->startOfMonth(), [1666, 1666, 1668]],
            'Case #2' => [6, 5000, Currency::LEU, Carbon::now()->startOfMonth(), [833, 833, 833, 833, 833, 835]],
            'Case #3' => [6, 12345, Currency::EUR, Carbon::now()->startOfMonth(), [2057, 2057, 2057, 2057, 2057, 2060]],
            'Case #4' => [3, 4, Currency::LEU, Carbon::now()->startOfMonth(), [1, 1, 2]],
        ];
    }
}
