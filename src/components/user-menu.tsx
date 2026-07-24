import { useNavigate } from "@tanstack/react-router";
import { LogOut, Settings, User as UserIcon } from "lucide-react";
import { useQueryClient } from "@tanstack/react-query";
import { Avatar, AvatarFallback, AvatarImage } from "@/components/ui/avatar";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { useAuth } from "@/hooks/use-auth";

function initials(name?: string | null, email?: string | null) {
  const source = (name ?? email ?? "?").trim();
  const parts = source.split(/\s+/);
  const first = parts[0]?.[0] ?? "";
  const second = parts.length > 1 ? parts[parts.length - 1][0] : "";
  return (first + second).toUpperCase() || "?";
}

export function UserMenu() {
  const { user, signOut } = useAuth();
  const navigate = useNavigate();
  const qc = useQueryClient();
  const name = (user?.user_metadata?.full_name as string | undefined) ?? user?.email ?? "";
  const avatar = user?.user_metadata?.avatar_url as string | undefined;

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="ghost" size="icon" className="rounded-full">
          <Avatar className="h-8 w-8">
            {avatar ? <AvatarImage src={avatar} alt={name} /> : null}
            <AvatarFallback>{initials(name, user?.email)}</AvatarFallback>
          </Avatar>
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-56">
        <DropdownMenuLabel className="font-normal">
          <div className="flex flex-col">
            <span className="truncate text-sm font-medium">{name}</span>
            <span className="truncate text-xs text-muted-foreground">{user?.email}</span>
          </div>
        </DropdownMenuLabel>
        <DropdownMenuSeparator />
        <DropdownMenuItem onSelect={() => navigate({ to: "/settings/profile" })}>
          <UserIcon className="mr-2 h-4 w-4" />
          Profile
        </DropdownMenuItem>
        <DropdownMenuItem onSelect={() => navigate({ to: "/settings/organization" })}>
          <Settings className="mr-2 h-4 w-4" />
          Settings
        </DropdownMenuItem>
        <DropdownMenuSeparator />
        <DropdownMenuItem
          onSelect={async () => {
            await qc.cancelQueries();
            qc.clear();
            await signOut();
            navigate({ to: "/auth", replace: true });
          }}
        >
          <LogOut className="mr-2 h-4 w-4" />
          Sign out
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
