<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Ticket Confirmation</title>
</head>

<body style="margin:0; padding:17px; background:#f5f5f5; font-family:Verdana, 'Times New Roman', serif;">

    <div style="max-width:750px; margin:0 auto; background:#ffffff;
    box-shadow:0 14px 30px rgba(0,0,0,0.08); overflow:hidden; 
    padding:40px 5px; 
    font-family:Verdana, 'Times New Roman', serif;">


        <!-- Header -->
       <div style="padding:30px 20px; text-align:center; font-family:Verdana, 'Times New Roman', serif;">
            <img src="{{ $companyLogo }}"
                alt="Logo" style="width:100%; height:auto; object-fit:contain;">
        </div>

        <!-- Content -->
        <div style="padding:35px 40px; color:#2c3e50; font-size:14px; line-height:1.7; 
            font-family:Verdana, 'Times New Roman', serif;">

            <p style="font-family:Verdana, 'Times New Roman', serif;">
                Dear <strong>{{ $name }}</strong>,
            </p>

            <p style="font-family:Verdana, 'Times New Roman', serif;">
                Your ticket has been created with the ticket ID #{{ $ticket_id }} and subject <b>"{{$subject}}"</b>
            </p>
            <p>Someone from our customer service team will review it & respond shortly</p>
            <div style="background:#f8fbff; border:1px solid #d6e4f2; padding:22px 25px; border-radius:8px; 
                margin-bottom:25px;margin-top:10px; font-family:Verdana, 'Times New Roman', serif;">
                
                <div style="font-size:18px; font-weight:600; color:#003c71; margin-bottom:12px; 
                    border-left:4px solid #f38b2b; padding-left:10px; font-family:Verdana, 'Times New Roman', serif;">
                    Ticket Details
                </div>

                <table style="width:100%; border-collapse:collapse; font-family:Verdana, 'Times New Roman', serif;">
                    <tr>
                        <td style="padding:8px 0; font-size:14px; color:#34495e;">
                            <strong>Ticket ID:</strong>
                        </td>
                        <td style="padding:8px 0; font-size:14px; color:#34495e;">
                            #{{ $ticket_id }}
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:8px 0; font-size:14px; color:#34495e;">
                            <strong>Status:</strong>
                        </td>
                        <td style="padding:8px 0; font-size:14px; color:#f38b2b; font-weight:600;">
                            Open
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Account Access -->
            @if(!empty($is_customer_new))
            <div style="background:#f8fbff; border:1px solid #d6e4f2; padding:22px 25px; border-radius:8px; 
                margin-bottom:25px; font-family:Verdana, 'Times New Roman', serif;">
                
                <div style="font-size:18px; font-weight:600; color:#003c71; margin-bottom:12px; 
                    border-left:4px solid #f38b2b; padding-left:10px;">
                    Your Account Access
                </div>

                <table style="width:100%; border-collapse:collapse; font-family:Verdana, 'Times New Roman', serif;">
                    <tr>
                        <td style="padding:8px 0; font-size:14px;">
                            <strong>Email:</strong>
                        </td>
                        <td style="padding:8px 0; font-size:14px;">
                            {{ $email }}
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:8px 0; font-size:14px;">
                            <strong>Password:</strong>
                        </td>
                        <td style="padding:8px 0; font-size:14px;">
                            {{ $password }}
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:8px 0; font-size:14px;">
                            <strong>Login URL:</strong>
                        </td>
                        <td style="padding:8px 0; font-size:14px;">
                            <a href="{{ $login_url }}" style="color:#f38b2b; text-decoration:none;">
                                {{ $login_url }}
                            </a>
                        </td>
                    </tr>
                </table>

                <a href="{{ $login_url }}" 
                    style="display:inline-block; background:#f38b2b; padding:12px 25px; color:#fff; 
                    border-radius:6px; text-decoration:none; font-size:14px; margin-top:12px;
                    font-family:Verdana, 'Times New Roman', serif;">
                    Login to Dashboard
                </a>
            </div>
            @endif

            <div style="text-align:left; 
            color:#2c3e50; 
            font-family:Verdana,'Times New Roman', serif; line-height:1.6;">

                <p style="font-size:14px;margin:0; ">Regards,</p>
                <p style="font-size:14px;margin:0;">{{ $footer_team_name }}</p>
                <p style="font-size:14px;margin:5px 0 0 0; ">{{ $companyName }}</p>
                <p style="font-size:10px;margin:3px 0 0 0;">{{ $companyAddress }}</p>
                <p style="font-size:10px;margin:3px 0 0 0;">{{ $cinnumber }}</p>

            </div>

        </div>
    <!-- Footer -->


      


    </div>

</body>
</html>
