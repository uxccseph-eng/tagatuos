<?php

namespace Tests\Feature;

use Tests\TestCase;

class BooksPageTest extends TestCase
{
    public function test_books_require_preview_access_and_company_setup(): void
    {
        $this->get('/books')->assertRedirect('/books/general-journal');
        $this->get('/books/general-journal')->assertRedirect('/signin');
        $this->withSession(['preview_access' => true])->get('/books/general-journal')->assertRedirect('/company/setup');
    }

    public function test_book_pages_preserve_the_reference_navigation_and_escape_company_details(): void
    {
        $this->withSession(['preview_access' => true, 'company_setup' => ['company_name' => '<script>alert(1)</script>', 'full_name' => 'Demo Owner']]);
        foreach (['general-journal', 'general-ledger', 'subsidiary-ledger', 'subsidiary-sales', 'subsidiary-purchase', 'cash-disbursements', 'cash-receipts', 'cash-analysis', 'settlement-trace'] as $book) {
            $this->get('/books/'.$book)->assertOk()->assertViewIs('books')->assertViewHas('book', $book)
                ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)
                ->assertSee('aria-label="Books navigation"', false)->assertSee('books-tabs-wrap')
                ->assertSee('books-report.js')->assertSee('working-period.js')->assertSee('data-period-from', false)->assertSee('data-period-to', false)->assertDontSee('sheet-tabs')->assertDontSee('EOPT Validated');
        }
        $this->get('/books/unknown-book')->assertNotFound();
    }

    public function test_sheets_link_to_books_in_the_sidebar(): void
    {
        $this->withSession(['preview_access' => true, 'company_setup' => ['company_name' => 'Sample Studio', 'full_name' => 'Demo Owner']]);
        foreach (['sales', 'collections', 'cwt', 'purchases', 'payments', 'wtp', 'journal'] as $sheet) {
            $this->get('/sheets/'.$sheet)->assertOk()->assertSee('href="'.route('books').'"', false);
        }
    }
}
