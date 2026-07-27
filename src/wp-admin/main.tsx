import { createRoot } from "react-dom/client";
import { StrictMode } from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { AdminApp } from "./AdminApp";
import "./admin.css";

const container = document.getElementById("eventos-admin-root");

if (container) {
  const view = container.dataset.view ?? "dashboard";
  const queryClient = new QueryClient({
    defaultOptions: { queries: { refetchOnWindowFocus: false, retry: 1 } },
  });

  createRoot(container).render(
    <StrictMode>
      <QueryClientProvider client={queryClient}>
        <AdminApp view={view} />
      </QueryClientProvider>
    </StrictMode>,
  );
}
