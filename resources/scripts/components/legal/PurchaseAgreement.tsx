import { cn } from '@/lib/cn';
import { LegalLink } from './LegalLinks';

/** "By purchasing you agree to the Terms of Service and Refund Policy", with both linked. */
export default function PurchaseAgreement({ className }: { className?: string }) {
    return (
        <p className={cn('text-xs text-muted-foreground', className)}>
            By purchasing you agree to the <LegalLink document='terms' /> and <LegalLink document='refunds' />.
        </p>
    );
}
