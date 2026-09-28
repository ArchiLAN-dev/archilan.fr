import { cva, type VariantProps } from "class-variance-authority";

/**
 * Button look shared by the dialogs and the screens that open them (story 39.11). A class helper rather
 * than a component, so a `<button>`, a `<Link>` or a Radix `Close` can all wear it.
 */
export const buttonVariants = cva(
  "inline-flex min-h-9 items-center justify-center gap-1.5 rounded-lg px-3 text-sm font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent/60 disabled:cursor-not-allowed disabled:opacity-50",
  {
    variants: {
      variant: {
        primary: "bg-accent text-white hover:bg-accent-hover",
        danger: "bg-danger text-white hover:opacity-90",
        secondary: "border border-border text-foreground hover:border-accent",
        ghost: "text-muted-foreground hover:bg-surface-2 hover:text-foreground",
      },
    },
    defaultVariants: { variant: "secondary" },
  },
);

export type ButtonVariant = NonNullable<VariantProps<typeof buttonVariants>["variant"]>;
