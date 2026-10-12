<?php

namespace App\Http\Middleware;

use App\Support\Commerce\RetiredCustomerCheckout;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AbortRetiredCustomerPaymentLinks
{
    public function handle(Request $request, Closure $next): Response
    {
        RetiredCustomerCheckout::abortPaymentLinks();
    }
}
