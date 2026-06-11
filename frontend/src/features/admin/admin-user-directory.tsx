"use client";

import {Search, ShieldAlert, UserPlus, Users} from "lucide-react";
import type {FormEvent, ReactNode} from "react";
import {useEffect, useId, useMemo, useState} from "react";

import {apiFetch} from "@/lib/apiFetch";
import {env} from "@/lib/env";

type AdminUser = {
    id: string;
    email: string;
    displayName: string | null;
    role: "admin" | "member" | "user";
    roles: string[];
    status: "active" | "deleted";
    createdAt: string;
    updatedAt: string;
    deletedAt: string | null;
};

type DirectoryState =
    | { kind: "loading" }
    | { kind: "ready"; users: AdminUser[] }
    | { kind: "denied"; message: string }
    | { kind: "error"; message: string };

type FieldErrors = Partial<Record<"email" | "password" | "displayName", string>>;

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
    const [query, setQuery] = useState("");
    const [role, setRole] = useState("all");
    const [state, setState] = useState<DirectoryState>({kind: "loading"});
    const [changingUserId, setChangingUserId] = useState<string | null>(null);
    const [mutationError, setMutationError] = useState<string | null>(null);
    const [pendingChange, setPendingChange] = useState<{
        user: AdminUser;
        targetRole: "user" | "member"
    } | null>(null);
    const [creationMessage, setCreationMessage] = useState<string | null>(null);

    const requestUrl = useMemo(() => {
        const params = new URLSearchParams();

        if (query.trim() !== "") {
            params.set("q", query.trim());
        }

        if (role !== "all") {
            params.set("role", role);
        }

        const suffix = params.toString();

        return `${env.apiBaseUrl}/admin/users${suffix === "" ? "" : `?${suffix}`}`;
    }, [query, role]);

    useEffect(() => {
        const controller = new AbortController();

        async function loadUsers() {
            setState({kind: "loading"});
            setMutationError(null);
            setCreationMessage(null);

            try {
                const response = await fetch(requestUrl, {
                    credentials: "include",
                    signal: controller.signal,
                });

                if (response.status === 401 || response.status === 403) {
                    setState({
                        kind: "denied",
                        message: "Accès réservé aux admins ArchiLAN.",
                    });

                    return;
                }

                if (!response.ok) {
                    setState({
                        kind: "error",
                        message: "Impossible de charger l'annuaire utilisateurs.",
                    });

                    return;
                }

                const payload: unknown = await response.json();
                const data = isDirectoryPayload(payload) ? payload.data : [];
                setState({kind: "ready", users: data});
            } catch (error) {
                if (error instanceof DOMException && error.name === "AbortError") {
                    return;
                }

                setState({
                    kind: "error",
                    message: "Impossible de contacter l'API utilisateurs.",
                });
            }
        }

        void loadUsers();

        return () => controller.abort();
    }, [requestUrl]);

    const hasFilters = query.trim() !== "" || role !== "all";

    function requestRoleChange(user: AdminUser, targetRole: "user" | "member") {
        setPendingChange({user, targetRole});
    }

    async function executeRoleChange() {
        if (!pendingChange || state.kind !== "ready") {
            setPendingChange(null);
            return;
        }

        const {user, targetRole} = pendingChange;
        setPendingChange(null);

        const previousUsers = state.users;
        const optimisticUser = withRole(user, targetRole);
        setMutationError(null);
        setChangingUserId(user.id);
        setState({kind: "ready", users: nextUsersForCurrentFilter(previousUsers, optimisticUser, role)});

        try {
            const response = await apiFetch(`${env.apiBaseUrl}/admin/users/${user.id}/role`, {
                body: JSON.stringify({role: targetRole, confirmed: true}),
                headers: {"Content-Type": "application/json"},
                method: "PATCH",
            });

            if (!response.ok) {
                throw new Error("role-change-failed");
            }

            const payload: unknown = await response.json();
            const updatedUser = isUserPayload(payload) ? payload.data : optimisticUser;
            setState({kind: "ready", users: nextUsersForCurrentFilter(previousUsers, updatedUser, role)});
        } catch {
            setState({kind: "ready", users: previousUsers});
            setMutationError("Le changement de rôle a échoué. L'affichage a été restauré.");
        } finally {
            setChangingUserId(null);
        }
    }

    async function createAdminAccount(input: { email: string; password: string; displayName: string }) {
        const response = await apiFetch(`${env.apiBaseUrl}/admin/users/admins`, {
            body: JSON.stringify(input),
            headers: {"Content-Type": "application/json"},
            method: "POST",
        });

        const payload: unknown = await response.json();

        if (!response.ok) {
            throw new AdminCreationError(fieldErrorsFromPayload(payload));
        }

        const createdUser = isUserPayload(payload) ? payload.data : null;
        if (createdUser && state.kind === "ready") {
            setState({
                kind: "ready",
                users: role === "all" || role === "admin" ? [createdUser, ...state.users] : state.users,
            });
        }

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
                onChange={(event) => setQuery(event.target.value)}
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
                        onChange={(event) => setRole(event.target.value)}
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
                            <p className="font-semibold text-foreground">{user.email}</p>
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

function withRole(user: AdminUser, role: "user" | "member"): AdminUser {
    return {
        ...user,
        role,
        roles: role === "member" ? ["ROLE_USER", "ROLE_MEMBER"] : ["ROLE_USER"],
    };
}

function nextUsersForCurrentFilter(users: AdminUser[], updatedUser: AdminUser, roleFilter: string): AdminUser[] {
    const nextUsers = users.map((user) => (user.id === updatedUser.id ? updatedUser : user));

    if (roleFilter === "all" || roleFilter === updatedUser.role) {
        return nextUsers;
    }

    return nextUsers.filter((user) => user.id !== updatedUser.id);
}

function isDirectoryPayload(payload: unknown): payload is { data: AdminUser[] } {
    if (!payload || typeof payload !== "object" || !("data" in payload)) {
        return false;
    }

    return Array.isArray((payload as { data: unknown }).data);
}

function isUserPayload(payload: unknown): payload is { data: AdminUser } {
    if (!payload || typeof payload !== "object" || !("data" in payload)) {
        return false;
    }

    const data = (payload as { data: unknown }).data;

    return Boolean(data && typeof data === "object" && "id" in data && "role" in data);
}

function fieldErrorsFromPayload(payload: unknown): FieldErrors {
    if (!payload || typeof payload !== "object" || !("error" in payload)) {
        return {email: "Le formulaire contient une erreur."};
    }

    const error = (payload as { error: unknown }).error;
    if (!error || typeof error !== "object" || !("details" in error)) {
        return {email: "Le formulaire contient une erreur."};
    }

    const details = (error as { details: unknown }).details;
    if (!details || typeof details !== "object") {
        return {email: "Le formulaire contient une erreur."};
    }

    return {
        email: firstDetail(details, "email"),
        password: firstDetail(details, "password"),
        displayName: firstDetail(details, "displayName"),
    };
}

function firstDetail(details: object, key: keyof FieldErrors): string | undefined {
    const value = (details as Record<string, unknown>)[key];

    return Array.isArray(value) && typeof value[0] === "string" ? value[0] : undefined;
}

class AdminCreationError extends Error {
    constructor(readonly fieldErrors: FieldErrors) {
        super("admin-creation-failed");
    }
}
