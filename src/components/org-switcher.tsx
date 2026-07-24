import { useState } from "react";
import { Check, ChevronsUpDown, Loader2, Plus } from "lucide-react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useServerFn } from "@tanstack/react-start";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from "@/components/ui/dialog";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { useCurrentOrg } from "@/hooks/use-current-org";
import { createOrganization } from "@/lib/organizations.functions";
import { ROLE_LABEL } from "@/lib/permissions";

export function OrgSwitcher() {
  const { organizations, currentOrg, setCurrentOrgId } = useCurrentOrg();
  const [open, setOpen] = useState(false);
  const [name, setName] = useState("");
  const qc = useQueryClient();
  const createFn = useServerFn(createOrganization);

  const createMutation = useMutation({
    mutationFn: (input: { name: string }) => createFn({ data: input }),
    onSuccess: (org) => {
      toast.success("Organization created");
      qc.invalidateQueries({ queryKey: ["organizations"] });
      setCurrentOrgId(org.id);
      setOpen(false);
      setName("");
    },
    onError: (err: Error) => toast.error(err.message),
  });

  return (
    <>
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button variant="ghost" className="w-full justify-between px-2">
            <span className="flex min-w-0 flex-col items-start">
              <span className="truncate text-sm font-medium">
                {currentOrg?.name ?? "Select organization"}
              </span>
              {currentOrg ? (
                <span className="text-xs text-muted-foreground">{ROLE_LABEL[currentOrg.role]}</span>
              ) : null}
            </span>
            <ChevronsUpDown className="ml-2 h-4 w-4 opacity-60" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start" className="w-64">
          <DropdownMenuLabel>Organizations</DropdownMenuLabel>
          {organizations.map((org) => (
            <DropdownMenuItem key={org.id} onSelect={() => setCurrentOrgId(org.id)}>
              <Check
                className={"mr-2 h-4 w-4 " + (currentOrg?.id === org.id ? "opacity-100" : "opacity-0")}
              />
              <span className="flex-1 truncate">{org.name}</span>
              <span className="ml-2 text-xs text-muted-foreground">{ROLE_LABEL[org.role]}</span>
            </DropdownMenuItem>
          ))}
          <DropdownMenuSeparator />
          <DropdownMenuItem onSelect={() => setOpen(true)}>
            <Plus className="mr-2 h-4 w-4" />
            New organization
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>

      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Create organization</DialogTitle>
            <DialogDescription>Set up a new workspace. You will be the owner.</DialogDescription>
          </DialogHeader>
          <div className="space-y-2">
            <Label htmlFor="org-name">Name</Label>
            <Input
              id="org-name"
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="Acme Events"
            />
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setOpen(false)}>
              Cancel
            </Button>
            <Button
              disabled={name.trim().length < 2 || createMutation.isPending}
              onClick={() => createMutation.mutate({ name: name.trim() })}
            >
              {createMutation.isPending ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : null}
              Create
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}
