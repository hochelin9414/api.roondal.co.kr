from fastapi import APIRouter, Request

router = APIRouter()


@router.get("/get_ip")
async def get_ip(request: Request):
    return {"ip": request.client.host if request.client else ""}
