<?php

namespace App\Enums;

enum NotificationType: string
{
    use HasValues;

    case AccountCreated = 'accountCreated';
    case OtpSent = 'otpSent';
    case BookingRequested = 'bookingRequested';
    case BookingApproved = 'bookingApproved';
    case BookingRejected = 'bookingRejected';
    case PaymentSubmitted = 'paymentSubmitted';
    case PaymentVerified = 'paymentVerified';
    case BoardingAvailable = 'boardingAvailable';
    case WatchlistMatch = 'watchlistMatch';
    case RoommateMatchFound = 'roommateMatchFound';
    case ReportSubmitted = 'reportSubmitted';
    case ComplaintSubmitted = 'complaintSubmitted';
    case SearchMatch = 'searchMatch';
    case DemandSpike = 'demandSpike';
    case PriceDrop = 'priceDrop';
    case InquiryReply = 'inquiryReply';
    case SmartReminder = 'smartReminder';
    case SuspiciousChatActivity = 'suspiciousChatActivity';
    case SafetyAlert = 'safetyAlert';
    case LowAvailability = 'lowAvailability';
}
