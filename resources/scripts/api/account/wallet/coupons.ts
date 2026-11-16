import { useMutation, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { formatTokens, walletQueryKey } from '@/api/account/wallet/queries';
import { clientRedeemCouponMutation } from '@/api/generated/@tanstack/react-query.gen';
import { storeQueryKey } from '@/api/store/queries';
import { notifyHttpError } from '@/plugins/notifications';

/** The exact payload; the generated type marks every field optional (it comes from the example). */
interface RedeemedCoupon {
    code: string;
    tokens: number;
    balance: number;
}

/** Redeems a free-tokens coupon on the Wallet page and refreshes the balance and history. */
export const useRedeemCoupon = () => {
    const queryClient = useQueryClient();

    return useMutation({
        ...clientRedeemCouponMutation(),
        onSuccess: (data) => {
            const { tokens } = data as RedeemedCoupon;

            toast.success('Coupon redeemed', {
                description: `${formatTokens(tokens)} tokens were added to your wallet.`,
            });
        },
        onSettled: async () => {
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: walletQueryKey }),
                queryClient.invalidateQueries({ queryKey: storeQueryKey }),
            ]);
        },
        onError: (error) => notifyHttpError(error, 'Unable to redeem the coupon'),
    });
};
