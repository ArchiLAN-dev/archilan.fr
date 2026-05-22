from __future__ import annotations

import asyncio
import logging
import os
import uuid
from collections.abc import Awaitable, Callable
from typing import Any

from kubernetes_asyncio import client, config as k8s_config  # type: ignore[import-untyped]
from kubernetes_asyncio.client.api_client import ApiClient  # type: ignore[import-untyped]

from bridge.core.config import Config
from bridge.ports.runtime import RunResult

log = logging.getLogger("bridge.adapters.kubernetes")

_POLL_INTERVAL = 2.0


class KubernetesRuntimeAdapter:
    """Runs AP processes as Kubernetes Jobs/Pods.

    Volumes use hostPath — correct for single-node k3s on VPS.
    For multi-node clusters, replace hostPath volumes with PersistentVolumeClaims
    and ensure the PVC is bound to the node where data directories live.
    """

    def __init__(self, config: Config) -> None:
        self._config = config
        self._namespace = config.ap_k8s_namespace

    def supports_generate(self) -> bool:
        return bool(self._config.ap_image)

    def supports_server(self) -> bool:
        return bool(self._config.ap_image)

    async def _load_config(self) -> None:
        try:
            k8s_config.load_incluster_config()
        except k8s_config.ConfigException:
            await k8s_config.load_kube_config()

    async def run_generation(
        self,
        *,
        yamls_dir: str,
        output_dir: str,
        worlds_dir: str,
        race_mode: bool = False,
        on_progress: Callable[[str], Awaitable[None]] | None = None,
    ) -> RunResult:
        await self._load_config()
        job_name = f"ap-gen-{uuid.uuid4().hex[:8]}"
        cmd = [
            "python", "-m", "Generate",
            "--yaml_dir", "/data/yamls",
            "--output_dir", "/data/output",
        ]
        if race_mode:
            cmd.append("--race")

        job = client.V1Job(
            api_version="batch/v1",
            kind="Job",
            metadata=client.V1ObjectMeta(name=job_name, namespace=self._namespace),
            spec=client.V1JobSpec(
                ttl_seconds_after_finished=300,
                backoff_limit=0,
                template=client.V1PodTemplateSpec(
                    spec=client.V1PodSpec(
                        restart_policy="Never",
                        containers=[client.V1Container(
                            name="generate",
                            image=self._config.ap_image,
                            command=cmd,
                            volume_mounts=[
                                client.V1VolumeMount(name="yamls", mount_path="/data/yamls"),
                                client.V1VolumeMount(name="output", mount_path="/data/output"),
                                client.V1VolumeMount(name="worlds", mount_path="/data/worlds"),
                            ],
                        )],
                        volumes=[
                            client.V1Volume(
                                name="yamls",
                                host_path=client.V1HostPathVolumeSource(path=yamls_dir),
                            ),
                            client.V1Volume(
                                name="output",
                                host_path=client.V1HostPathVolumeSource(path=output_dir),
                            ),
                            client.V1Volume(
                                name="worlds",
                                host_path=client.V1HostPathVolumeSource(path=worlds_dir),
                            ),
                        ],
                    ),
                ),
            ),
        )

        async with ApiClient() as api:
            batch = client.BatchV1Api(api)
            core = client.CoreV1Api(api)

            await batch.create_namespaced_job(namespace=self._namespace, body=job)
            log.info("k8s generation job created: %s", job_name)

            pod_name = await self._wait_for_pod(core, f"job-name={job_name}", timeout=600.0)
            logs_str = ""
            if pod_name:
                await self._wait_pod_done(core, pod_name, timeout=600.0)
                logs_str = await self._read_pod_logs(core, pod_name, on_progress)

            completed_job = await batch.read_namespaced_job(name=job_name, namespace=self._namespace)
            exit_code = 0 if (completed_job.status and completed_job.status.succeeded) else 1

        return RunResult(exit_code=exit_code, logs=logs_str)

    async def start_server(
        self,
        *,
        seed_path: str,
        output_dir: str,
        worlds_dir: str,
        port: int,
    ) -> str:
        await self._load_config()
        seed_filename = os.path.basename(seed_path)
        pod_name = f"ap-server-{uuid.uuid4().hex[:8]}"

        pod = client.V1Pod(
            api_version="v1",
            kind="Pod",
            metadata=client.V1ObjectMeta(
                name=pod_name,
                namespace=self._namespace,
                labels={"app": "ap-server"},
            ),
            spec=client.V1PodSpec(
                restart_policy="Always",
                containers=[client.V1Container(
                    name="server",
                    image=self._config.ap_image,
                    command=[
                        "python", "MultiServer.py",
                        "--host", "0.0.0.0",
                        "--port", str(port),
                        f"/data/output/{seed_filename}",
                    ],
                    ports=[client.V1ContainerPort(container_port=port, protocol="TCP")],
                    volume_mounts=[
                        client.V1VolumeMount(name="output", mount_path="/data/output"),
                        client.V1VolumeMount(name="worlds", mount_path="/data/worlds"),
                    ],
                )],
                volumes=[
                    client.V1Volume(
                        name="output",
                        host_path=client.V1HostPathVolumeSource(path=output_dir),
                    ),
                    client.V1Volume(
                        name="worlds",
                        host_path=client.V1HostPathVolumeSource(path=worlds_dir),
                    ),
                ],
            ),
        )

        async with ApiClient() as api:
            core = client.CoreV1Api(api)
            await core.create_namespaced_pod(namespace=self._namespace, body=pod)
            log.info("k8s server pod created: %s", pod_name)

        return pod_name

    async def stop_server(self, handle: str) -> None:
        await self._load_config()
        async with ApiClient() as api:
            core = client.CoreV1Api(api)
            try:
                await core.delete_namespaced_pod(name=handle, namespace=self._namespace)
                log.info("k8s server pod deleted: %s", handle)
            except Exception as exc:
                log.warning("k8s stop_server error pod=%s: %s", handle, exc)

    async def get_yaml_template(self, game: str, *, worlds_dir: str) -> str:
        await self._load_config()
        job_name = f"ap-tmpl-{uuid.uuid4().hex[:8]}"

        job = client.V1Job(
            api_version="batch/v1",
            kind="Job",
            metadata=client.V1ObjectMeta(name=job_name, namespace=self._namespace),
            spec=client.V1JobSpec(
                ttl_seconds_after_finished=60,
                backoff_limit=0,
                template=client.V1PodTemplateSpec(
                    spec=client.V1PodSpec(
                        restart_policy="Never",
                        containers=[client.V1Container(
                            name="template",
                            image=self._config.ap_image,
                            command=["python", "-m", "Generate", "--template", game],
                            volume_mounts=[
                                client.V1VolumeMount(name="worlds", mount_path="/data/worlds"),
                            ],
                        )],
                        volumes=[
                            client.V1Volume(
                                name="worlds",
                                host_path=client.V1HostPathVolumeSource(path=worlds_dir),
                            ),
                        ],
                    ),
                ),
            ),
        )

        async with ApiClient() as api:
            batch = client.BatchV1Api(api)
            core = client.CoreV1Api(api)

            await batch.create_namespaced_job(namespace=self._namespace, body=job)
            pod_name = await self._wait_for_pod(core, f"job-name={job_name}", timeout=30.0)
            if pod_name is None:
                raise RuntimeError("template job pod did not start in time")

            await self._wait_pod_done(core, pod_name, timeout=30.0)
            logs: str = await core.read_namespaced_pod_log(
                name=pod_name,
                namespace=self._namespace,
            )

        return logs

    # ---------------------------------------------------------------------------
    # Helpers
    # ---------------------------------------------------------------------------

    async def _wait_for_pod(
        self, core: Any, label_selector: str, timeout: float
    ) -> str | None:
        deadline = asyncio.get_event_loop().time() + timeout
        while asyncio.get_event_loop().time() < deadline:
            pods = await core.list_namespaced_pod(
                namespace=self._namespace,
                label_selector=label_selector,
            )
            for pod in pods.items or []:
                phase = pod.status.phase if pod.status else None
                if phase in ("Running", "Succeeded", "Failed"):
                    name: str = pod.metadata.name
                    return name
            await asyncio.sleep(_POLL_INTERVAL)
        return None

    async def _wait_pod_done(self, core: Any, pod_name: str, timeout: float) -> None:
        deadline = asyncio.get_event_loop().time() + timeout
        while asyncio.get_event_loop().time() < deadline:
            pod = await core.read_namespaced_pod(name=pod_name, namespace=self._namespace)
            phase = pod.status.phase if pod.status else None
            if phase in ("Succeeded", "Failed"):
                return
            await asyncio.sleep(_POLL_INTERVAL)

    async def _read_pod_logs(
        self,
        core: Any,
        pod_name: str,
        on_progress: Callable[[str], Awaitable[None]] | None,
    ) -> str:
        lines: list[str] = []
        try:
            log_data: str = await core.read_namespaced_pod_log(
                name=pod_name,
                namespace=self._namespace,
            )
            for line in (log_data or "").splitlines():
                lines.append(line)
                if on_progress:
                    await on_progress(line)
        except Exception as exc:
            log.warning("k8s log read error pod=%s: %s", pod_name, exc)
        return "\n".join(lines[-100:])
