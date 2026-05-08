<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Work Order</title>
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
                <h2 style="margin:0; padding:0;">{{$wo_data->company_name}}</h2>
                <p style="margin:0; padding:0;">{{$wo_data->company_address}}</p>
                <p style="margin:0; padding:0;">GST No: {{$wo_data->company_gst_no}}</p>
            </td>
        </tr>
        
    </table>
    <h3 style="text-align:center; margin-top:20px;">WORK ORDER</h3>
    
</div>
        <!-- ORDER DETAILS -->
        <table class="details">
            <tr>
                <td>Your Quotation No:</td>
                <td>{{$wo_data->quotation_no}}</td>
                <td>WO No:</td>
                <td>{{$wo_data->wo_no}}</td>
            </tr>
            <tr>
                <td>Your Quotation Date:</td>
                <td>{{$wo_data->quotation_date}}</td>
                <td>WO Date:</td>
                <td>{{$wo_data->wo_date}}</td>
            </tr>
        </table>

        <!-- VENDOR DETAILS -->
        <div class="vendor-info">
            <p><span class="bold">To: </span>{{$wo_data->supplier_company_name}},</p>
            <p>{{ $wo_data->supplier_address . ' ' . $wo_data->pincode.' '.$wo_data->city.'('.$wo_data->state.')' }}</p>
            <p>Contact Person: {{$wo_data->shipping_contact_person}}</p>
            <p>Phone: {{$wo_data->supplier_mobile}}</p>
            <p>Email: {{$wo_data->supplier_email}}</p>
            <p>GST No: {{$wo_data->supplier_gst}}</p>
        </div>

        <!-- ITEM DETAILS TABLE -->
        <table class="table" styles="padding-bottom: 100px;">
            <tr>
                <th>S.NO.</th>
                <th>HSN Code</th>
                <th>Item Description</th>
                <th>Qty</th>
                <th>Units</th>
                <th>Price</th>
                <th>Basic</th>
                <th>GST</th>
                <th>GST Amount</th>
                <th>Total</th>
            </tr>
            @if(!empty($wo_data->items_header))
                <tr>
                    <td colspan="9" class="header-row" style="text-align:center;font-weight:bold">
                        {{ $wo_data->items_header }}
                    </td>
                </tr>
            @endif

            @foreach($poItems as $key => $item)
                <tr>
                
                    <td>{{ $key + 1 }}</td>
                    <td class="left">{{ $item->hsnCode }}</td>
                    <td class="left">{{ $item->item_name }}</td>
                    <td>{{ number_format($item->qty) }}</td>
                    <td>{{ $item->unit }}</td>
                    <td>{{$currency->currency_symbol}} {{ number_format($item->price, 2) }}</td>
                    <td>{{$currency->currency_symbol}} {{ number_format($item->sub_total, 2) }}</td>
                    <td>{{$item->gst}}%</td>
                    <td>{{$currency->currency_symbol}} {{ number_format($item->gst_amount, 2) }}</td>
                    <td>{{$currency->currency_symbol}} {{ number_format($item->total, 2) }}</td>
                
                </tr>
            @endforeach
        </table>
        @php
    $gstType = strtolower($wo_data->gst_type ?? '');
    $gstPercent = $po_data->gst_per ?? 0;
    $grandTotal2 = $subTotal;
@endphp

<div class="total-section">
    

   

    <p><strong>Grand Total: {{$currency->currency_symbol}} {{ number_format($grandTotal, 2) }}</strong></p>
            <p><strong>Amount in Words: {{$amountInWords}} </strong></p>
            @if(!empty($wo_data->add_note))
                 <p><strong>Notes:</strong> {!! $wo_data->add_note !!}</p>
            @endif

        </div>

        <!-- TERMS & CONDITIONS -->
        <htmlpagefooter name="customFooter">
            <div class="terms">
                <h3>Terms & Conditions:</h3>
                @php
                    $termsData = $wo_data->terms_conditions ? json_decode($wo_data->terms_conditions, true) : null;
                    
                    
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
                        <!-- <strong>{{$wo_data->company_name}}</strong><br> -->
                        <!-- {{$wo_data->company_billing_address}}<br> -->
                        {!! $wo_data->company_billing_address !!}<br>
                        <!-- <strong>GST No:</strong> {{$wo_data->company_gst_no}}<br> -->
                        <!-- <strong>Contact:</strong> {{$wo_data->company_mobile}} -->
                    </td>
                    <td>
                        <!-- <strong>{{$wo_data->company_name}}</strong><br> -->
                        <!-- {{$wo_data->shipping_address}}<br> -->
                        {!! $wo_data->shipping_address !!}<br>
                        <!-- <strong>GST No:</strong> {{$wo_data->company_gst_no}}<br> -->
                        <!-- <strong>Contact:</strong> {{$wo_data->company_mobile}} -->
                    </td>
                </tr>
                <tr>
                    <td colspan="2" class="signature-cell">
                    <strong>{{$wo_data->company_name}}</strong><br><br>
                        @if(!empty($sign))
                            <img src="{{ asset($sign) }}" alt="Signature" class="signature-img"><br>
                        @endif
                        <br>
                        Authorised Signatory
                    </td>
                </tr>
            </table>
            </htmlpagefooter>
        
        
    </div>
</body>
</html>