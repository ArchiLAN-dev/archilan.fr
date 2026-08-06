"use client";

import Link from "next/link";
import {Search, ShieldAlert, UserPlus, Users} from "lucide-react";
import type {FormEvent, ReactNode} from "react";
import {useId, useState} from "react";
import {useQuery, useQueryClient} from "@tanstack/react-query";

import {DEFAULT_STALE_TIME} from "@/lib/query-client";
import {
    createAdminUser,
    fetchAdminUsers,
    updateAdminUserRole,
    type AdminUser,
    type AdminUserFieldErrors as FieldErrors,
} from "./admin-users-api";

type DirectoryState =
    | { kind: "loading" }
    | { kind: "ready"; users: AdminUser[] }
    | { kind: "denied"; message: string }
    | { kind: "error"; message: string };

const roleLabels: Record<AdminUser["role"], string> = {
    admin: "Admin",
    member: "Membre",
    user: "Utilisateur",
};

const statusLabels: Record<AdminUser["status"], string> = {
    active: "Actif",
    deleted: "Supprimé",
};

export function AdminUserDirectory() {
    const queryClient = useQueryClient();
    const [query, setQuery] = useState("");
    const [role, setRole] = useState("all");
    const [changingUserId, setChangingUserId] = useState<string | null>(null);
    const [mutationError, setMutationError] = useState<string | null>(null);
    const [pendingChange, setPendingChange] = useState<{
        user: AdminUser;
        targetRole: "user" | "member"
    } | null>(null);
    const [creationMessage, setCreationMessage] = useState<string | null>(null);

    // Filters in the key: changing the search or role refetches automatically, and TanStack's
    // signal replaces the old AbortController on rapid keystrokes. fetchAdminUsers never throws
    // (denied/error are encoded in the result kind), so - like the old effect - no retry.
    const {data} = useQuery({
        queryKey: ["admin-users", query.trim(), role],
        queryFn: ({signal}) => fetchAdminUsers(query.trim(), role, signal),
        staleTime: DEFAULT_STALE_TIME,
        retry: false,
    });
    const state: DirectoryState = data ?? {kind: "loading"};

    const hasFilters = query.trim() !== "" || role !== "all";

    // The old effect cleared the banners on every filter-triggered reload.
    function applyFilterChange(apply: () => void) {
        setMutationError(null);
        setCreationMessage(null);
        apply();
    }

    function requestRoleChange(user: AdminUser, targetRole: "user" | "member") {
        setPendingChange({user, targetRole});
    }

    async function executeRoleChange() {
        if (!pendingChange) {
            return;
        }

        const {user, targetRole} = pendingChange;
        setPendingChange(null);
        setMutationError(null);
        setChangingUserId(user.id);

        const updated = await updateAdminUserRole(user.id, targetRole);

        if (updated === null) {
            setMutationError("Le changement de rôle a échoué. L'affichage a été restauré.");
        } else {
            await queryClient.invalidateQueries({queryKey: ["admin-users"]});
        }

        setChangingUserId(null);
    }

    async function createAdminAccount(input: { email: string; password: string; displayName: string }) {
        const result = await createAdminUser(input);

        if (!result.ok) {
            if (result.reason === "validation") {
                throw new AdminCreationError(result.fieldErrors);
            }
            throw new Error("admin-creation-failed");
        }

        await queryClient.invalidateQueries({queryKey: ["admin-users"]});
        setCreationMessage("Compte admin créé.");
    }

    return (
        <section className="grid w-full min-w-0 grid-cols-1 gap-8 px-4 py-10">
            <header className="grid gap-3">
                <p className="text-sm font-semibold uppercase tracking-[0.18em] text-accent-warm">
                    Backoffice
                </p>
                <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
                    <div>
                        <h1 className="font-heading text-4xl font-bold leading-tight text-foreground">
                            Annuaire utilisateurs
                        </h1>
                        <p className="mt-3 max-w-2xl text-muted-foreground">
                            Recherche, consultation et gestion des rôles utilisateur/membre.
                        </p>
                    </div>
                </div>
            </header>

            <div className="grid gap-4 border border-border bg-surface p-4 md:grid-cols-[1fr_220px]">
                <label className="grid gap-2 text-sm font-medium text-foreground">
                    Recherche
                    <span
                        className="flex min-h-11 items-center gap-2 border border-border bg-background px-3 focus-within:border-accent">
            <Search aria-hidden="true" className="size-4 shrink-0 text-muted-foreground"/>
            <input
                className="min-w-0 flex-1 bg-transparent outline-none placeholder:text-muted-foreground"
                onChange={(event) => applyFilterChange(() => setQuery(event.target.value))}
                placeholder="Email ou nom affiché"
                type="search"
                value={query}
            />
          </span>
                </label>

                <label className="grid gap-2 text-sm font-medium text-foreground">
                    Rôle
                    <select
                        className="min-h-11 border border-border bg-background px-3 text-foreground outline-none focus:border-accent"
                        onChange={(event) => applyFilterChange(() => setRole(event.target.value))}
                        value={role}
                    >
                        <option value="all">Tous les rôles</option>
                        <option value="user">Utilisateur</option>
                        <option value="member">Membre</option>
                        <option value="admin">Admin</option>
                    </select>
                </label>
            </div>

            {mutationError ? (
                <p className="border border-danger/50 bg-surface p-3 text-sm text-danger" role="alert">
                    {mutationError}
                </p>
            ) : null}

            {creationMessage ? (
                <p className="border border-success/50 bg-surface p-3 text-sm text-success" role="status">
                    {creationMessage}
                </p>
            ) : null}

            {state.kind === "ready" ? <AdminCreationForm onCreate={createAdminAccount}/> : null}

            {pendingChange ? (
                <RoleChangeConfirmDialog
                    onCancel={() => setPendingChange(null)}
                    onConfirm={() => void executeRoleChange()}
                    pending={pendingChange}
                />
            ) : null}

            <DirectoryBody
                changingUserId={changingUserId}
                hasFilters={hasFilters}
                onChangeRole={requestRoleChange}
                state={state}
            />
        </section>
    );
}

function AdminCreationForm({
                               onCreate,
                           }: {
    onCreate: (input: { email: string; password: string; displayName: string }) => Promise<void>;
}) {
    const [email, setEmail] = useState("");
    const [password, setPassword] = useState("");
    const [displayName, setDisplayName] = useState("");
    const [errors, setErrors] = useState<FieldErrors>({});
    const [genericError, setGenericError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);

    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setSubmitting(true);
        setErrors({});
        setGenericError(null);

        try {
            await onCreate({email, password, displayName});
            setEmail("");
            setPassword("");
            setDisplayName("");
        } catch (error) {
            if (error instanceof AdminCreationError) {
                setErrors(error.fieldErrors);
            } else {
                setGenericError("Impossible de créer le compte admin pour le moment.");
            }
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <form className="grid gap-4 border border-border bg-surface p-4" onSubmit={submit}>
            <div className="flex items-center gap-2">
                <UserPlus aria-hidden="true" className="size-5 text-accent-text"/>
                <h2 className="font-heading text-2xl font-semibold text-foreground">Créer un admin</h2>
            </div>
            {genericError ? (
                <p className="border border-danger/50 bg-surface p-3 text-sm text-danger" role="alert">
                    {genericError}
                </p>
            ) : null}
            <div className="grid gap-4 md:grid-cols-3">
                <TextField
                    autoComplete="email"
                    error={errors.email}
                    label="Email"
                    onChange={setEmail}
                    type="email"
                    value={email}
                />
                <TextField
                    autoComplete="name"
                    error={errors.displayName}
                    label="Nom affiché"
                    maxLength={80}
                    onChange={setDisplayName}
                    type="text"
                    value={displayName}
                />
                <TextField
                    autoComplete="new-password"
                    error={errors.password}
                    label="Mot de passe initial"
                    onChange={setPassword}
                    type="password"
                    value={password}
                />
            </div>
            <div>
                <button
                    className="inline-flex min-h-11 items-center justify-center rounded bg-accent px-4 text-sm font-semibold text-white transition-colors hover:bg-accent-hover disabled:cursor-not-allowed disabled:opacity-60"
                    disabled={submitting}
                    type="submit"
                >
                    {submitting ? "Création..." : "Créer"}
                </button>
            </div>
        </form>
    );
}

function TextField({
                       autoComplete,
                       error,
                       label,
                       maxLength,
                       onChange,
                       type,
                       value,
                   }: {
    autoComplete: string;
    error?: string;
    label: string;
    maxLength?: number;
    onChange: (value: string) => void;
    type: string;
    value: string;
}) {
    const id = useId();
    const errorId = `${id}-error`;

    return (
        <label className="grid gap-2 text-sm font-medium text-foreground">
            {label}
            <input
                aria-describedby={error ? errorId : undefined}
                aria-invalid={Boolean(error)}
                autoComplete={autoComplete}
                className="min-h-11 border border-border bg-background px-3 outline-none focus:border-accent"
                maxLength={maxLength}
                onChange={(event) => onChange(event.target.value)}
                type={type}
                value={value}
            />
            {error ? (
                <span className="text-xs text-danger" id={errorId}>
          {error}
        </span>
            ) : null}
        </label>
    );
}

function DirectoryBody({
                           changingUserId,
                           hasFilters,
                           onChangeRole,
                           state,
                       }: {
    changingUserId: string | null;
    hasFilters: boolean;
    onChangeRole: (user: AdminUser, targetRole: "user" | "member") => void;
    state: DirectoryState;
}) {
    if (state.kind === "loading") {
        return (
            <div className="grid gap-3 border border-border bg-surface p-5" aria-busy="true">
                <div className="h-5 w-48 bg-surface-2"/>
                <div className="h-12 bg-surface-2"/>
                <div className="h-12 bg-surface-2"/>
            </div>
        );
    }

    if (state.kind === "denied") {
        return (
            <EmptyPanel
                icon={<ShieldAlert aria-hidden="true" className="size-8 text-danger"/>}
                title="Accès admin requis"
            >
                {state.message}
            </EmptyPanel>
        );
    }

    if (state.kind === "error") {
        return (
            <EmptyPanel
                icon={<ShieldAlert aria-hidden="true" className="size-8 text-danger"/>}
                title="Annuaire indisponible"
            >
                {state.message}
            </EmptyPanel>
        );
    }

    if (state.users.length === 0) {
        return (
            <EmptyPanel icon={<Users aria-hidden="true" className="size-8 text-accent-text"/>}
                        title={hasFilters ? "Aucun résultat" : "Aucun utilisateur"}>
                {hasFilters
                    ? "Aucun compte ne correspond à cette recherche ou à ce filtre."
                    : "Aucun compte utilisateur n'est encore disponible dans l'annuaire."}
            </EmptyPanel>
        );
    }

    return (
        <div className="border border-border bg-surface">
            <div className="hidden overflow-x-auto lg:block">
            <table className="w-full min-w-[900px] border-collapse text-left text-sm">
                <thead className="border-b border-border text-muted-foreground">
                <tr>
                    <th className="px-4 py-3 font-medium">Utilisateur</th>
                    <th className="px-4 py-3 font-medium">Rôle</th>
                    <th className="px-4 py-3 font-medium">Statut</th>
                    <th className="px-4 py-3 font-medium">Créé le</th>
                    <th className="px-4 py-3 font-medium">Action</th>
                </tr>
                </thead>
                <tbody>
                {state.users.map((user) => (
                    <tr className="border-b border-border last:border-b-0" key={user.id}>
                        <td className="px-4 py-4">
                            <Link
                                className="font-semibold text-foreground transition-colors hover:text-accent-text"
                                href={`/admin/utilisateurs/${user.id}`}
                            >
                                {user.email}
                            </Link>
                            <p className="mt-1 text-muted-foreground">{user.displayName ?? "Nom affiché non renseigné"}</p>
                        </td>
                        <td className="px-4 py-4">
                <span
                    className="inline-flex min-h-8 items-center border border-accent/50 px-3 text-xs font-semibold text-accent-text">
                  {roleLabels[user.role]}
                </span>
                        </td>
                        <td className="px-4 py-4">
                <span className={user.status === "deleted" ? "text-danger" : "text-success"}>
                  {statusLabels[user.status]}
                </span>
                        </td>
                        <td className="px-4 py-4 text-muted-foreground">
                            <time
                                dateTime={user.createdAt}>{new Intl.DateTimeFormat("fr-FR").format(new Date(user.createdAt))}</time>
                        </td>
                        <td className="px-4 py-4">
                            <RoleActionButton
                                changing={changingUserId === user.id}
                                onChangeRole={onChangeRole}
                                user={user}
                            />
                        </td>
                    </tr>
                ))}
                </tbody>
            </table>
            </div>

            <ul className="divide-y divide-border lg:hidden">
                {state.users.map((user) => (
                    <li className="space-y-3 p-4" key={user.id}>
                        <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0">
                                <p className="truncate font-semibold text-foreground">{user.email}</p>
                                <p className="text-xs text-muted-foreground">{user.displayName ?? "Nom affiché non renseigné"}</p>
                            </div>
                            <span className={`shrink-0 text-xs font-medium ${user.status === "deleted" ? "text-danger" : "text-success"}`}>
                                {statusLabels[user.status]}
                            </span>
                        </div>
                        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                            <span className="inline-flex min-h-6 items-center border border-accent/50 px-2 font-semibold text-accent-text">
                                {roleLabels[user.role]}
                            </span>
                            <time dateTime={user.createdAt}>
                                Créé le {new Intl.DateTimeFormat("fr-FR").format(new Date(user.createdAt))}
                            </time>
                        </div>
                        <RoleActionButton
                            changing={changingUserId === user.id}
                            onChangeRole={onChangeRole}
                            user={user}
                        />
                    </li>
                ))}
            </ul>
        </div>
    );
}

function RoleActionButton({
                              changing,
                              onChangeRole,
                              user,
                          }: {
    changing: boolean;
    onChangeRole: (user: AdminUser, targetRole: "user" | "member") => void;
    user: AdminUser;
}) {
    if (user.status === "deleted" || user.role === "admin") {
        return <span className="text-sm text-muted-foreground">Non modifiable</span>;
    }

    const targetRole = user.role === "user" ? "member" : "user";
    const label = user.role === "user" ? "Promouvoir membre" : "Rétrograder utilisateur";

    return (
        <button
            className="inline-flex min-h-10 items-center justify-center rounded border border-border px-3 text-sm font-semibold text-foreground transition-colors hover:border-accent disabled:cursor-not-allowed disabled:opacity-60"
            disabled={changing}
            onClick={() => onChangeRole(user, targetRole)}
            type="button"
        >
            {changing ? "Mise à jour..." : label}
        </button>
    );
}

function RoleChangeConfirmDialog({
                                     onCancel,
                                     onConfirm,
                                     pending,
                                 }: {
    onCancel: () => void;
    onConfirm: () => void;
    pending: { user: AdminUser; targetRole: "user" | "member" };
}) {
    const action = pending.targetRole === "member" ? "promouvoir en membre" : "rétrograder en utilisateur";

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div
                aria-labelledby="role-dialog-title"
                aria-modal="true"
                className="grid w-full max-w-md gap-6 border border-border bg-background p-6"
                role="alertdialog"
            >
                <h2 className="font-heading text-xl font-semibold text-foreground" id="role-dialog-title">
                    Confirmer le changement de rôle
                </h2>
                <p className="text-sm text-muted-foreground">
                    {`Confirmer : ${action} pour `}
                    <strong className="text-foreground">{pending.user.email}</strong>
                    {" ?"}
                </p>
                <div className="flex justify-end gap-3">
                    <button
                        className="inline-flex min-h-10 items-center justify-center rounded border border-border px-4 text-sm font-semibold text-foreground transition-colors hover:border-accent"
                        onClick={onCancel}
                        type="button"
                    >
                        Annuler
                    </button>
                    <button
                        className="inline-flex min-h-10 items-center justify-center rounded bg-accent px-4 text-sm font-semibold text-white transition-colors hover:bg-accent-hover"
                        onClick={onConfirm}
                        type="button"
                    >
                        Confirmer
                    </button>
                </div>
            </div>
        </div>
    );
}

function EmptyPanel({
                        children,
                        icon,
                        title,
                    }: Readonly<{
    children: ReactNode;
    icon: ReactNode;
    title: string;
}>) {
    return (
        <div className="grid justify-items-center gap-3 border border-border bg-surface p-8 text-center">
            {icon}
            <h2 className="font-heading text-2xl font-semibold text-foreground">{title}</h2>
            <p className="max-w-md text-sm leading-6 text-muted-foreground">{children}</p>
        </div>
    );
}

class AdminCreationError extends Error {
    constructor(readonly fieldErrors: FieldErrors) {
        super("admin-creation-failed");
    }
}
