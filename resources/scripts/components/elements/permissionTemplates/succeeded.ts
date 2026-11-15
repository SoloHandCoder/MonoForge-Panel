/** Runs a mutation and resolves whether it worked; the mutation itself reports a failure. */
export const succeeded = async (run: () => Promise<unknown>): Promise<boolean> => {
    try {
        await run();

        return true;
    } catch {
        return false;
    }
};
