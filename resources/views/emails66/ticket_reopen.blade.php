<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Ticket Reopen Notification</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap');
        
        /* Base Styles */
        body {
            font-family: 'Poppins', Arial, sans-serif;
            margin: 0;
            padding: 0;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
        }
        
        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
        }
        
        .header {
            background: linear-gradient(135deg, #2C5282 0%, #4299E1 100%);
            padding: 30px 0;
            text-align: center;
        }
        
        .logo-img {
            width: 230px;
            height: 80px;
            object-fit: contain;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.1));
        }
        
        .content {
            padding: 30px;
        }
        
        .ticket-card {
            margin: 25px 0;
            background: #FFFFFF;
            padding: 25px;
            border-radius: 8px;
            border-left: 4px solid #2C5282;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        
        .action-button {
            display: inline-block;
            background: linear-gradient(135deg, #2C5282 0%, #4299E1 100%);
            color: white;
            text-decoration: none;
            padding: 12px 30px;
            border-radius: 6px;
            font-weight: 500;
            font-size: 15px;
            box-shadow: 0 4px 6px rgba(50, 115, 220, 0.25);
            transition: all 0.3s ease;
        }
        
        .footer {
            border-top: 1px solid #EDF2F7;
            padding-top: 20px;
            text-align: center;
        }
        
        /* Mobile Styles */
        @media only screen and (max-width: 600px) {
            body {
                padding: 15px !important;
                background: #f5f7fa !important;
            }
            
            .email-container {
                border-radius: 8px !important;
                box-shadow: 0 5px 15px rgba(0,0,0,0.05) !important;
            }
            
            .header {
                padding: 25px 0 !important;
            }
            
            .logo-img {
                width: 160px !important;
                height: 60px !important;
            }
            
            .content {
                padding: 20px !important;
            }
            
            h1, h2 {
                font-size: 20px !important;
                margin: 10px 0 0 !important;
            }
            
            .ticket-card {
                padding: 20px 15px !important;
                margin: 20px 0 !important;
            }
            
            h3 {
                font-size: 16px !important;
                margin-bottom: 15px !important;
            }
            
            .action-button {
                padding: 10px 20px !important;
                font-size: 14px !important;
                display: block !important;
                margin: 0 auto !important;
                width: 80% !important;
                text-align: center !important;
            }
            
            table {
                display: block !important;
            }
            
            tr {
                display: block !important;
                margin-bottom: 10px !important;
            }
            
            td {
                display: block !important;
                width: 100% !important;
                padding: 4px 0 !important;
            }
            
            td:first-child {
                font-weight: 600 !important;
                color: #4A5568 !important;
            }
        }
    </style>
</head>
<body>
    <div style="padding: 40px 20px;">
        <div class="email-container">
            <!-- Header with blue gradient -->
            <div class="header">
                 <img src="{{ $companyLogo }}" alt="Logo" class="logo-img">
                <h1 style="color: white; margin: 15px 0 0; font-size: 24px; font-weight: 500;">Ticket Reopened Successfully</h1>
            </div>

            <div class="content">
                <p style="color: #4A5568; font-size: 15px; margin-bottom: 25px;">
                    Dear <strong style="color: #2C5282; font-weight: 600;">{{ $userName }}</strong>,
                </p>

                <p style="color: #4A5568; font-size: 15px; line-height: 1.6; margin-bottom: 25px;">
                    Your ticket has been <strong style="color: #2C5282;">Reopened</strong> successfully. You may continue the conversation with our support team regarding the issue.
                </p>

                <!-- Ticket Details Card -->
                <div class="ticket-card">
                    <h3 style="margin-top: 0; margin-bottom: 20px; color: #2C5282; font-size: 18px; font-weight: 600;">Ticket Details</h3>
                    <table style="width: 100%; border-collapse: collapse;">
                        <tr>
                            <td style="padding: 8px 0; color: #718096; font-size: 14px; width: 30%; vertical-align: top;"><strong>Ticket ID:</strong></td>
                            <td style="padding: 8px 0; color: #2D3748; font-size: 14px; font-weight: 500;">{{ $ticketId }}</td>
                        </tr>
                        <tr>
                            <td style="padding: 8px 0; color: #718096; font-size: 14px;"><strong>Title:</strong></td>
                            <td style="padding: 8px 0; color: #2D3748; font-size: 14px; font-weight: 500;">{{ $ticketTitle }}</td>
                        </tr>
                        <tr>
                            <td style="padding: 8px 0; color: #718096; font-size: 14px;"><strong>Status:</strong></td>
                            <td style="padding: 8px 0; color: #2C5282; font-size: 14px; font-weight: 500;">Reopened</td>
                        </tr>
                        <tr>
                            <td style="padding: 8px 0; color: #718096; font-size: 14px;"><strong>Reopened By:</strong></td>
                            <td style="padding: 8px 0; color: #2D3748; font-size: 14px; font-weight: 500;">{{ $closedBy }}</td>
                        </tr>
                        <tr>
                            <td style="padding: 8px 0; color: #718096; font-size: 14px;"><strong>Reopened On:</strong></td>
                            <td style="padding: 8px 0; color: #2D3748; font-size: 14px; font-weight: 500;">{{ $closedOn }}</td>
                        </tr>
                    </table>
                </div>


                <!-- Footer -->
                <div class="footer">
                    <p style="color: #718096; font-size: 14px; margin-bottom: 5px;">
                        <strong style="color: #2C5282;">{{ $companyName }}</strong>
                    </p>
                    <p style="color: #718096; font-size: 13px; margin-top: 5px;">
                        {{ $footer_team_name }} | <a href="#" style="color: #4299E1; text-decoration: none;">{{ $footer_email }}</a>
                    </p>
                    <p style="color: #A0AEC0; font-size: 12px; margin-top: 15px;">
                        Reply to this email to add more comments to your ticket.
                    </p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>