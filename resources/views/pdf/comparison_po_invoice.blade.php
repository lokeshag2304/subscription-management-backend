<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Order</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            line-height: 1.2;
        }
        .container {
            width: 900px;
            margin: auto;
            border: 1px solid grey;
            padding: 15px;
            height:100%;
        }
        .header {
            text-align: center;
            font-weight: bold;
            margin-bottom: 8px;
        }
        .header h2 {
            font-size: 18px;
            margin: 2px 0;
        }
        .header h3 {
            font-size: 16px;
            margin: 2px 0;
        }
        .header p {
            font-size: 12px;
            margin: 2px 0;
            line-height:10px;
        }
        .details {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
            font-size: 11px;
        }
        .details td {
            padding: 4px;
            border: 1px solid grey;
        }
        .details td:first-child {
            font-weight: bold;
            width: 30%;
        }
        .vendor-info {
            margin-bottom: 8px;
            border: 1px solid grey;
            padding: 8px;
            font-size: 11px;
        }
        .vendor-info p {
            margin: 4px 0;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
            font-size: 11px;
        }
        .table, .table th, .table td {
            border: 1px solid grey;
        }
        .table th, .table td {
            padding: 5px;
            text-align: left;
        }
        .table th {
            background: #f0f0f0;
            font-size: 11px;
        }
        .left {
            text-align: left !important;
        }
        .bold {
            font-weight: bold;
        }
        .total-section {
            margin-top: 8px;
            text-align: right;
            /* font-weight: bold; */
            font-size: 11px;
        }
        .terms {
            margin-top: 8px;
            border: 1px solid grey;
            padding: 8px;
            font-size: 11px;
        }
        .terms h3 {
            margin: 4px 0;
            font-size: 12px;
        }
        .address-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
            font-size: 11px;
            border: 1px solid grey;
        }
        .address-table th, .address-table td {
            border: 1px solid grey;
            padding: 4px;
            vertical-align: top;
        }
        .address-table th {
            text-align: left;
        }
        .signatory-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            font-size: 11px;
            border: 1px solid grey;
        }
        .signatory-table td {
            border: 1px solid grey;
            padding: 8px;
            text-align: center;
            vertical-align: middle;
        }
        .combined-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
            font-size: 11px;
            border: 1px solid grey;
        }
        .combined-table th, .combined-table td {
            border: 1px solid grey;
            padding: 4px;
            vertical-align: top;
        }
        .combined-table th {
            text-align: left;
        }
        .signature-cell {
            text-align: right !important;
            padding-top: 20px;
        }
        .signature-img {
            width: 80px;
            height: 40px;
            margin-bottom: 5px;
        }
        
    </style>
</head>
<body>
    <div class="container">
        
        <!-- HEADER -->
       
        <div class="header">
    <table width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td width="100px" valign="top">
                @if(!empty($pdf_logo))
                    <img style="width:100px;" src="{{ asset($pdf_logo) }}" alt="Company Logo">
                @endif
            </td>
            <td valign="top" style="text-align:center;">
                <h2 style="margin:0; padding:0;">{{$po_data->company_name}}</h2>
                <p style="margin:0; padding:0;">{{$po_data->company_address}}</p>
                <p style="margin:0; padding:0;">GST No: {{$po_data->company_gst_no}}</p>
            </td>
        </tr>
        
    </table>
    <h3 style="text-align:center; margin-top:20px;">PURCHASE ORDER</h3>
    
</div>
        <!-- ORDER DETAILS -->
        <table class="details">
            <tr>
                <td>Your Quotation No:</td>
                <td>{{$po_data->quotation_no}}</td>
                <td>WO No:</td>
                <td>{{$po_data->po_no}}</td>
            </tr>
            <tr>
                <td>Your Quotation Date:</td>
                <td>{{$po_data->quotation_date}}</td>
                <td>PO Date:</td>
                <td>{{$po_data->po_date}}</td>
            </tr>
        </table>

      
        <!-- ITEM DETAILS TABLE -->
        @php
    $products = $cc['Data']; // multiple products
    $vendors = collect($cc['vendorDetails']);
@endphp
<h3 style="text-align:Left; margin-top:20px;">Price Comparison</h3>
<table class="table" >
    <thead>
        <tr>
            <th>S.NO.</th>
            <th>Item Name</th>
            
           
            @foreach ($vendors as $vendor)
                <th>{{ $vendor->vendor_name }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach ($products as $key => $item)
            <tr>
                <td>{{ $key + 1 }}</td>
                <td>{{ $item->item_name }}</td>
                
                
                @foreach ($vendors as $vendor)
                    @php
                        $pricing = collect($item->vendor_pricing)->firstWhere('vendor_id', $vendor->id);
                    @endphp
                    <td>
                        @if ($pricing)
                        {{ number_format($pricing->price, 2) }}x{{ $item->qty }} = {{$currency->currency_symbol}}{{ number_format($pricing->sub_total, 2) }}<br>
                            <small>{{ $pricing->sub_total_display }}</small>
                        @else
                            N/A
                        @endif
                    </td>
                @endforeach
            </tr>
        @endforeach

        <tr style="font-weight: bold; background-color: #f0f0f0;">
            <td></td>
        <td>Grand Total</td>
        @foreach ($vendors as $vendor)
            <td colspan="1">
                {{$currency->currency_symbol}}{{ number_format($vendor->grand_total, 2) }}
            </td>
        @endforeach
    </tr>
    </tbody>
</table>

        @php
  
@endphp

<div class="total-section">
    
            @if(!empty($po_data->add_note))
                 <p><strong>Notes:</strong> {!! $po_data->add_note !!}</p>
            @endif

        </div>

        <!-- TERMS & CONDITIONS -->
        <htmlpagefooter name="customFooter">
            <div class="terms">
                <h3>Terms & Conditions:</h3>
                @php
                    $termsData = $po_data->terms_conditions ? json_decode($po_data->terms_conditions, true) : null;
                    
                    
                @endphp

                @if(!empty($termsData))
                    @foreach($termsData['terms'] as $term)
                        <p>{{ $term['title'] }} :- {{ $term['description'] }}</p>
                    @endforeach
                @else
                    <p>No terms available.</p>
                @endif
            </div>

            <table class="combined-table">
                <tr>
                    <th style="width:50%">Billing Address</th>
                    <th style="width:50%">Shipping (DELIVERY) Address</th>
                </tr>
                <tr>
                    <td>
                        {!! $po_data->company_billing_address !!}<br>
                    </td>
                    <td>
                      {!! $po_data->shipping_address !!}<br>
                    </td>
                </tr>
                <tr>
                    <td colspan="2" class="signature-cell">
                    <strong>{{$po_data->company_name}}</strong><br><br>
                        <br>
                        Authorised Signatory
                    </td>
                </tr>
            </table>
            </htmlpagefooter>
        
        
    </div>
</body>
</html>