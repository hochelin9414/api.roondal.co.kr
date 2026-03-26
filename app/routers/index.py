from fastapi import APIRouter
from fastapi.responses import RedirectResponse

router = APIRouter()


@router.get("/")
@router.get("/index")
async def index():
    return RedirectResponse(url="/docs")
