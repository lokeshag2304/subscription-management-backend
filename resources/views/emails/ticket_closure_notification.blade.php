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
                Dear <strong>{{ $userName }}</strong>,
            </p>

            <p style="font-family:Verdana, 'Times New Roman', serif;">
                Your ticket #{{ $ticketId }} <b>"{{$subject}}"</b> has been Resolved
            </p>
            <p>We hope that we've helped you to the best of your satisfaction. To re-open this ticket simply reply to this email.</p>
            <div style="background:#f8fbff; border:1px solid #d6e4f2; padding:22px 25px; border-radius:8px; 
                margin-bottom:25px;margin-top:10px; font-family:Verdana, 'Times New Roman', serif;">
                
                <div style="font-size:18px; font-weight:600; color:#003c71; margin-bottom:12px; 
                    border-left:4px solid #f38b2b; padding-left:10px; font-family:Verdana, 'Times New Roman', serif;">
                    Ticket Details
                </div>

                <table style="width:100%; border-collapse:collapse; font-family:Verdana, 'Times New Roman', serif;">
                    <tr>
                            <td style="padding: 8px 0; color: #718096; font-size: 14px; width: 30%; vertical-align: top;"><strong>Ticket ID:</strong></td>
                            <td style="padding: 8px 0; color: #2D3748; font-size: 14px; font-weight: 500;">#{{ $ticketId }}</td>
                        </tr>
                        <tr>
                            <td style="padding: 8px 0; color: #718096; font-size: 14px;"><strong>Title:</strong></td>
                            <td style="padding: 8px 0; color: #2D3748; font-size: 14px; font-weight: 500;">{{ $ticketTitle }}</td>
                        </tr>
                        <tr>
                            <td style="padding: 8px 0; color: #718096; font-size: 14px;"><strong>Status:</strong></td>
                            <td style="padding: 8px 0; color: #2C5282; font-size: 14px; font-weight: 500;">Resolved</td>
                        </tr>
                        <tr>
                            <td style="padding: 8px 0; color: #718096; font-size: 14px;"><strong>Resolved By:</strong></td>
                            <td style="padding: 8px 0; color: #2D3748; font-size: 14px; font-weight: 500;">{{ $closedBy }}</td>
                        </tr>
                        <tr>
                            <td style="padding: 8px 0; color: #718096; font-size: 14px;"><strong>Resolved On:</strong></td>
                            <td style="padding: 8px 0; color: #2D3748; font-size: 14px; font-weight: 500;">{{ $closedOn }}</td>
                        </tr>
                </table>
            </div>

          

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
